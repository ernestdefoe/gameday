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
            /*
             * 🚨 `week.season` as well as the teams: the preview names the
             * week, and the SPORT it is written in is read off the season. Both
             * would otherwise be a lazy load per fixture — which on a Saturday
             * morning is two queries for every game about to kick off.
             */
            ->with(['homeTeam', 'awayTeam', 'week.season'])
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
            $game = PickEvent::with(['homeTeam', 'awayTeam', 'week.season'])->find($row->event_id);

            if ($game === null || $game->status !== PickEvent::STATUS_FINISHED) {
                continue;
            }

            if ($this->settings->stickyWhileLive()) {
                $this->setSticky($row->discussion_id, false);
            }

            $box = $this->boxScore->forEvent((int) $game->id);
            $postId = null;

            if ($this->settings->recaps() && $author !== null) {
                $postId = $this->post($row->discussion_id, $author, $this->recap($game)->text($this->game($game), $box));
            }

            /*
             * 🚨 `stats_at` is set only when the recap already HAS statistics —
             * which happens when a game settles late enough that Picks got the
             * box score first. Leaving it null the rest of the time is what
             * puts the thread in front of `enrich()`.
             */
            $row->state = GamedayThread::RESOLVED;
            $row->recap_post_id = $postId;
            $row->stats_at = $postId !== null && $this->recap($game)->usable($box) ? Carbon::now() : null;
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

            /*
             * 🚨 Whether a box score is usable does not depend on the sport —
             * it is two sides with figures in them — but the game is loaded
             * first anyway, so there is one `recap($game)` in this method
             * rather than one that takes the game and one that quietly does
             * not. The eager load is two queries either way.
             */
            $game = PickEvent::with(['homeTeam', 'awayTeam', 'week.season'])->find($row->event_id);

            if (!$this->recap($game)->usable($box)) {
                continue;
            }

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

            $post->setContentAttribute($this->recap($game)->text($this->game($game), $box), $author);
            $post->save();

            $row->stats_at = Carbon::now();
            $row->save();

            $rewritten++;
        }

        return $rewritten;
    }

    /**
     * Rewrites opening posts written before the fixture carried a lead-in.
     *
     * 🚨 Lives here rather than in the command, alongside the code that WROTE
     * those posts. What counts as an opener this extension generated, and what
     * it should say now, are the same question `openOne()` answers — and a copy
     * of that judgement in a console command is a copy that drifts the first
     * time the preview changes.
     *
     * 🚨 THREE conditions before anything is touched, and they are the reason
     * this is safe on a live board: the post must be the discussion's first, it
     * must have been posted by the account Game Day posts as, and its text must
     * still be a shape this extension generated. A post somebody has edited is
     * somebody's writing.
     *
     * @param  callable(string, string): void|null $report
     * @return array{rewritten: int, skipped: int, retitled: int}
     */
    public function rewriteOpeners(
        int $limit = 200,
        bool $withTitles = false,
        bool $includeCurrent = false,
        bool $dryRun = false,
        ?callable $report = null
    ): array {
        $author = $this->author();

        $rewritten = 0;
        $skipped = 0;
        $retitled = 0;

        if ($author === null) {
            return compact('rewritten', 'skipped', 'retitled');
        }

        $rows = GamedayThread::query()->orderByDesc('id')->get();

        foreach ($rows as $row) {
            if ($rewritten >= $limit) {
                break;
            }

            $game = PickEvent::with(['homeTeam', 'awayTeam', 'week.season'])->find($row->event_id);
            $discussion = Discussion::find($row->discussion_id);

            if ($game === null || $discussion === null || $discussion->first_post_id === null) {
                $skipped++;

                continue;
            }

            $post = CommentPost::find($discussion->first_post_id);

            if ($post === null || (int) $post->user_id !== (int) $author->id) {
                $skipped++;

                continue;
            }

            $content = (string) $post->content;

            if (! $this->isGeneratedOpener($content, $includeCurrent)) {
                $skipped++;

                continue;
            }

            $text = $this->preview($game)->text($this->previewOf($game));

            $title = $withTitles ? $this->title($game) : $discussion->title;
            $titleChanges = $withTitles && $title !== $discussion->title && $this->isGeneratedTitle($discussion->title, $game);

            // Nothing new to say is not a rewrite. A rerun over a season should
            // cost nothing and change nothing.
            if ($text === $content && ! $titleChanges) {
                $skipped++;

                continue;
            }

            if ($report !== null) {
                $report($titleChanges ? $discussion->title . '  ->  ' . $title : $discussion->title, $text);
            }

            if (! $dryRun) {
                $post->setContentAttribute($text, $author);
                $post->save();

                if ($titleChanges) {
                    /*
                     * 🚨 Written straight onto the model, NOT through the rename
                     * command. Flarum's rename posts an event into the
                     * discussion — "X changed the title" — and a backfill that
                     * did that would push sixty of them into sixty threads and
                     * bump every one of them to the top of the board.
                     */
                    $discussion->title = $title;
                    $discussion->save();
                }
            }

            if ($titleChanges) {
                $retitled++;
            }

            $rewritten++;
        }

        return compact('rewritten', 'skipped', 'retitled');
    }

    /* -------------------------------------------------------------- private */

    /**
     * Whether this post is still one this extension wrote.
     *
     * 🚨 Matched on the CLOSING line, not on "kicks off". A member opening
     * their own thread may well write "kicks off in an hour"; nobody writes
     * these sentences. Each has been the last line of every generated opener of
     * its era, which makes it a signature rather than a guess about wording.
     */
    protected function isGeneratedOpener(string $content, bool $includeCurrent): bool
    {
        if (str_contains($content, 'This thread opens before the game and stays here afterwards.')) {
            return true;
        }

        return $includeCurrent
            && str_contains($content, 'Thread is open — predictions, complaints and everything in between.');
    }

    /**
     * Whether the thread still has the title this extension gave it.
     *
     * 🚨 Compared against the UNRANKED form, because that is what the title was
     * before there were ranks — and a title that no longer matches is a title
     * somebody renamed. Renaming it back would undo a moderator's decision,
     * silently, across a whole season.
     */
    protected function isGeneratedTitle(string $title, PickEvent $game): bool
    {
        $joiner = $game->neutral_site ? ' vs ' : ' at ';
        $plain = trim(($game->awayTeam->name ?? 'Away') . $joiner . ($game->homeTeam->name ?? 'Home'));

        return $title === $plain;
    }


    protected function openOne(PickEvent $game, User $author): bool
    {
        $title = $this->title($game);

        return $this->db->transaction(function () use ($game, $author, $title) {
            $discussion = Discussion::start($title, $author);
            $discussion->save();

            $post = $this->post($discussion->id, $author, $this->preview($game)->text($this->previewOf($game)), $discussion);

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
     * named. A board with no tags extension at all skips this entirely rather
     * than failing.
     *
     * 🚨 A neutral site changes NOTHING here, and this comment used to claim it
     * did. A game at a neutral venue still has a designated home and away team
     * — the feed says which, and the only thing the flag changes is that the
     * title reads "A vs B" rather than "A at B". The fallback earns its keep
     * when NEITHER team has been mapped to a tag, which is a fact about the
     * mapping and not about the venue.
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

    /**
     * The recap for one game, in that game's own sport.
     *
     * 🚨 The sport comes from the GAME's season, not from the setting, whenever
     * the season names a league. A board following the NFL and the Premier
     * League has both on the same forum on the same Sunday, and one setting
     * would describe half of them in the wrong vocabulary — talking about yards
     * and turnovers under a 2–2 draw.
     *
     * 🚨 The setting is the fallback rather than dead weight: a season created
     * before any of this existed carries the default league, and an operator
     * following one competition should not have to set the sport again on every
     * season they create.
     */
    protected function recap(?PickEvent $game = null): Recap
    {
        return new Recap(
            $this->settings->emphasis(
                fn (string $extension): bool => $this->extensions->isEnabled($extension)
            ),
            $this->sports->get($this->sportFor($game)),
        );
    }

    protected function sportFor(?PickEvent $game): string
    {
        $season = $game?->week?->season;

        if ($season !== null && method_exists($season, 'leagueDefinition')) {
            $sport = $season->leagueDefinition()->sport;

            /*
             * 🚨 Only when this build actually HAS that sport. Picks can name a
             * league whose vocabulary was added there and not here — the two are
             * separate extensions on separate release lines — and the registry's
             * own fallback would then quietly answer gridiron. Falling through
             * to the setting instead at least uses a value somebody chose.
             */
            if ($this->sports->has($sport)) {
                return $sport;
            }
        }

        return $this->settings->sport();
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

        /*
         * 🚨 The rank goes in the TITLE, which is the one place it cannot be
         * corrected later — and that is exactly why it is safe to put there.
         * Picks freezes the rank onto the fixture, so "#4 Alabama at Wisconsin"
         * is still what that game was a year afterwards, however far the poll
         * has moved since. A title built from a rank kept on the CLUB would
         * have to be rewritten every Sunday, or lie.
         *
         * An unranked team is named plainly, so a board whose feed carries no
         * ranks at all gets exactly the titles it got before.
         */
        return trim(
            $this->ranked($game->awayTeam->name ?? 'Away', (int) $game->away_rank)
            . $joiner
            . $this->ranked($game->homeTeam->name ?? 'Home', (int) $game->home_rank)
        );
    }

    /** "#12 Alabama", or just "Alabama". */
    protected function ranked(string $name, int $rank): string
    {
        return $rank > 0 ? '#' . $rank . ' ' . $name : $name;
    }

    /**
     * The preview for one game, in that game's own sport.
     *
     * 🚨 Built exactly like `recap()` and for the same reason — the vocabulary
     * follows the FIXTURE'S league rather than one setting, so a board carrying
     * the NFL and the Premier League on the same Sunday does not tell half of
     * them that kick-off is a kickoff.
     */
    protected function preview(?PickEvent $game = null): Preview
    {
        return new Preview(
            $this->settings->emphasis(
                fn (string $extension): bool => $this->extensions->isEnabled($extension)
            ),
            $this->sports->get($this->sportFor($game)),
            $this->settings->timezone(),
        );
    }

    /**
     * Everything the preview is allowed to know about a game.
     *
     * 🚨 Assembled here rather than handing `Preview` the model, so the thing
     * that writes the post cannot reach for a relation and fire a query per
     * fixture — and so what it says can be asserted in a test with no database
     * anywhere near it. Same contract as `game()` above, for the same reasons.
     *
     * @return array<string, mixed>
     */
    protected function previewOf(PickEvent $game): array
    {
        return [
            'home_name' => (string) ($game->homeTeam->name ?? 'Home'),
            'away_name' => (string) ($game->awayTeam->name ?? 'Away'),
            'home_rank' => (int) $game->home_rank,
            'away_rank' => (int) $game->away_rank,
            'home_record' => (string) $game->home_record,
            'away_record' => (string) $game->away_record,
            /*
             * 🚨 The conference comes off the TEAMS, which is where Picks
             * already keeps it. A conference game is then two strings being
             * equal, and there is no third copy of the fact to drift out of
             * step with them.
             */
            'home_conference' => (string) ($game->homeTeam->conference ?? ''),
            'away_conference' => (string) ($game->awayTeam->conference ?? ''),
            'neutral_site' => (bool) $game->neutral_site,
            'kickoff' => $game->match_date,
            'venue' => (string) $game->venue,
            'venue_city' => (string) $game->venue_city,
            'broadcast' => (string) $game->broadcast,
            'week' => (string) ($game->week->name ?? ''),
        ];
    }
}
