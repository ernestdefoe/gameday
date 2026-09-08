<?php

namespace ErnestDefoe\Gameday\Service;

use Carbon\Carbon;
use ErnestDefoe\Gameday\GamedayThread;
use ErnestDefoe\Gameday\Service\Sports\Sports;
use ErnestDefoe\Gameday\TeamTag;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Resofire\Picks\PickEvent;

/**
 * Opening, starting and finishing a game's thread.
 *
 * 🚨 A game thread is an ORDINARY DISCUSSION. Not a content type, not a row
 * with a flag nothing else understands — so it is quotable, searchable,
 * moderatable, and still there if this extension is removed. That is the test
 * of whether a feature owns its content or merely borrows it.
 *
 * 🚨 Every pass is idempotent, because the schedule may overlap itself and a
 * site whose cron was off for a day catches up on the next tick rather than
 * skipping what it missed. `gameday_threads.event_id` is unique, which is what
 * actually enforces that rather than a check-then-insert.
 */
class Threads
{
    /** How long after a game a recap is still worth rewriting. */
    private const STATS_WINDOW_HOURS = 48;

    /** Recaps rewritten in one pass, so an hourly tick cannot run long. */
    private const ENRICH_BATCH = 40;

    /**
     * How long after kickoff a game is still worth opening a thread for.
     *
     * 🚨 Without it, installing this extension mid-season posts a thread for
     * every game ever played. The bound is on the game, not on the install
     * date, so it behaves the same however long the site has been running.
     */
    private const OPEN_WINDOW_HOURS = 6;

    public function __construct(
        protected ConnectionInterface $db,
        protected Settings $settings,
        protected BoxScore $boxScore,
        protected ExtensionManager $extensions,
        protected Sports $sports = new Sports()
    ) {
    }

    /** Opens threads for games about to kick off. */
    public function open(): int
    {
        $author = $this->author();

        if ($author === null) {
            return 0;
        }

        $now = Carbon::now();
        $opened = 0;

        $games = PickEvent::query()
            ->with(['homeTeam', 'awayTeam'])
            ->whereIn('status', [PickEvent::STATUS_SCHEDULED, PickEvent::STATUS_CLOSED])
            ->where('match_date', '<=', $now->copy()->addMinutes($this->settings->leadMinutes()))
            ->where('match_date', '>', $now->copy()->subHours(self::OPEN_WINDOW_HOURS))
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('gameday_threads')
                    ->whereColumn('gameday_threads.event_id', 'picks_events.id');
            })
            ->orderBy('match_date')
            ->get();

        foreach ($games as $game) {
            if ($this->openOne($game, $author)) {
                $opened++;
            }
        }

        return $opened;
    }

    /** Marks kicked-off threads as being played. */
    public function start(): int
    {
        $started = 0;

        $rows = GamedayThread::query()
            ->where('state', GamedayThread::OPEN)
            ->get();

        foreach ($rows as $row) {
            $game = PickEvent::find($row->event_id);

            if ($game === null || Carbon::now()->lt($game->match_date)) {
                continue;
            }

            if ($this->settings->stickyWhileLive()) {
                $this->setSticky($row->discussion_id, true);
            }

            $row->state = GamedayThread::LIVE;
            $row->live_at = Carbon::now();
            $row->save();

            $started++;
        }

        return $started;
    }

    /** Resolves finished games and leaves the recap behind. */
    public function resolve(): int
    {
        $author = $this->author();
        $resolved = 0;

        $rows = GamedayThread::query()
            ->whereIn('state', [GamedayThread::OPEN, GamedayThread::LIVE])
            ->get();

        foreach ($rows as $row) {
            $game = PickEvent::with(['homeTeam', 'awayTeam'])->find($row->event_id);

            if ($game === null || $game->status !== PickEvent::STATUS_FINISHED) {
                continue;
            }

            if ($this->settings->stickyWhileLive()) {
                $this->setSticky($row->discussion_id, false);
            }

            $box = $this->boxScore->forEvent((int) $game->id);
            $postId = null;

            if ($this->settings->recaps() && $author !== null) {
                $postId = $this->post($row->discussion_id, $author, $this->recap()->text($this->game($game), $box));
            }

            /*
             * 🚨 `stats_at` is set only when the recap already HAS statistics —
             * which happens when a game settles late enough that Picks got the
             * box score first. Leaving it null the rest of the time is what
             * puts the thread in front of `enrich()`.
             */
            $row->state = GamedayThread::RESOLVED;
            $row->recap_post_id = $postId;
            $row->stats_at = $postId !== null && $this->recap()->usable($box) ? Carbon::now() : null;
            $row->resolved_at = Carbon::now();
            $row->save();

            $resolved++;
        }

        return $resolved;
    }

    /**
     * Rewrites recaps that were posted before the box score arrived.
     *
     * 🚨 The recap goes up the moment a game settles, when the statistics do
     * not exist yet — the provider publishes them minutes to hours later.
     * Waiting would delay the one thing everybody in the thread is waiting for;
     * a second post would be noise. So the first post is the score and this
     * fills it out in place.
     *
     * 🚨 It gives up after two days. A game the provider never covered would
     * otherwise be re-examined for ever, and a recap that never gains
     * statistics is a perfectly good recap — it is the one every game got
     * before any of this existed.
     */
    public function enrich(): int
    {
        if (!$this->settings->recaps() || !$this->boxScore->available()) {
            return 0;
        }

        $author = $this->author();

        if ($author === null) {
            return 0;
        }

        $rewritten = 0;

        $rows = GamedayThread::query()
            ->where('state', GamedayThread::RESOLVED)
            ->whereNull('stats_at')
            ->whereNotNull('recap_post_id')
            ->where('resolved_at', '>', Carbon::now()->subHours(self::STATS_WINDOW_HOURS))
            ->limit(self::ENRICH_BATCH)
            ->get();

        foreach ($rows as $row) {
            $box = $this->boxScore->forEvent((int) $row->event_id);

            if (!$this->recap()->usable($box)) {
                continue;
            }

            $game = PickEvent::with(['homeTeam', 'awayTeam'])->find($row->event_id);
            $post = CommentPost::find($row->recap_post_id);

            if ($game === null || $post === null) {
                /*
                 * The post was deleted, or the game was. Marked done rather
                 * than retried for two days over something that will never
                 * come back.
                 */
                $row->stats_at = Carbon::now();
                $row->save();

                continue;
            }

            $post->setContentAttribute($this->recap()->text($this->game($game), $box), $author);
            $post->save();

            $row->stats_at = Carbon::now();
            $row->save();

            $rewritten++;
        }

        return $rewritten;
    }

    /* -------------------------------------------------------------- private */

    protected function openOne(PickEvent $game, User $author): bool
    {
        $title = $this->title($game);

        return $this->db->transaction(function () use ($game, $author, $title) {
            $discussion = Discussion::start($title, $author);
            $discussion->save();

            $post = $this->post($discussion->id, $author, $this->kickoffLine($game), $discussion);

            if ($post === null) {
                return false;
            }

            $this->tag($discussion, $game);

            GamedayThread::create([
                'event_id' => $game->id,
                'discussion_id' => $discussion->id,
                'state' => GamedayThread::OPEN,
                'opened_at' => Carbon::now(),
            ]);

            return true;
        });
    }

    /**
     * Posts into a discussion, and keeps the discussion's own counters honest.
     *
     * 🚨 `refreshLastPost` and `refreshCommentCount` are not optional. A post
     * inserted without them leaves a discussion whose listing row says it has
     * no replies and was last active when it was created — which is what a
     * board sorts by.
     */
    protected function post(int $discussionId, User $author, string $content, ?Discussion $discussion = null): ?int
    {
        $discussion ??= Discussion::find($discussionId);

        if ($discussion === null) {
            return null;
        }

        $post = new CommentPost();
        $post->discussion_id = $discussion->id;
        $post->user_id = $author->id;
        $post->created_at = Carbon::now();
        $post->setContentAttribute($content, $author);
        $post->save();

        if ($discussion->first_post_id === null) {
            $discussion->setFirstPost($post);
        }

        $discussion->setLastPost($post);
        $discussion->refreshLastPost();
        $discussion->refreshCommentCount();
        $discussion->save();

        return (int) $post->id;
    }

    /**
     * Puts the discussion in a tag.
     *
     * 🚨 The home team's tag, then the away team's, then whatever the operator
     * named. A neutral-site game belongs to neither, which is exactly when the
     * fallback earns its keep — and a board with no tags extension at all skips
     * this entirely rather than failing.
     */
    protected function tag(Discussion $discussion, PickEvent $game): void
    {
        if (!$this->extensions->isEnabled('flarum-tags')) {
            return;
        }

        $tagId = 0;

        foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
            $mapped = TeamTag::where('team_id', (int) $teamId)->value('tag_id');

            if ($mapped) {
                $tagId = (int) $mapped;
                break;
            }
        }

        $tagId = $tagId ?: $this->settings->fallbackTagId();

        if ($tagId < 1 || !$this->db->table('tags')->where('id', $tagId)->exists()) {
            return;
        }

        $this->db->table('discussion_tag')->insertOrIgnore([
            'discussion_id' => $discussion->id,
            'tag_id' => $tagId,
        ]);
    }

    /** @param bool $sticky */
    protected function setSticky(int $discussionId, bool $sticky): void
    {
        if (!$this->extensions->isEnabled('flarum-sticky')) {
            return;
        }

        $this->db->table('discussions')->where('id', $discussionId)->update(['is_sticky' => $sticky]);
    }

    protected function author(): ?User
    {
        $id = $this->settings->authorId();

        return $id > 0 ? User::find($id) : null;
    }

    protected function recap(): Recap
    {
        return new Recap(
            $this->settings->emphasis(
                fn (string $extension): bool => $this->extensions->isEnabled($extension)
            ),
            $this->sports->get($this->settings->sport()),
        );
    }

    /** @return array{home_name: string, away_name: string, home_score: int, away_score: int} */
    protected function game(PickEvent $game): array
    {
        return [
            'home_name' => (string) ($game->homeTeam->name ?? 'Home'),
            'away_name' => (string) ($game->awayTeam->name ?? 'Away'),
            'home_score' => (int) $game->home_score,
            'away_score' => (int) $game->away_score,
        ];
    }

    protected function title(PickEvent $game): string
    {
        // Away team first: "Alabama at Auburn" is how anybody would say it.
        $joiner = $game->neutral_site ? ' vs ' : ' at ';

        return trim(($game->awayTeam->name ?? 'Away') . $joiner . ($game->homeTeam->name ?? 'Home'));
    }

    protected function kickoffLine(PickEvent $game): string
    {
        return sprintf(
            "%s kicks off %s.\n\nThis thread opens before the game and stays here afterwards.",
            $this->title($game),
            $game->match_date->diffForHumans(),
        );
    }
}
