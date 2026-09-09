<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service;

use ErnestDefoe\Gameday\GamedayThread;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The one game worth putting on a sidebar right now.
 *
 * 🚨 A different question from the one the thread's own board answers. A board
 * beside a discussion is about THAT discussion's game; a widget on the home
 * page is about whatever is on. Conflating the two is how every game thread
 * ended up showing whichever game kicked off first, which is the bug this
 * separation exists to avoid repeating in the other direction.
 */
class CurrentGame
{
    /**
     * How long a finished game keeps the widget.
     *
     * 🚨 Otherwise the widget is blank all week. Live first and next kickoff
     * after it are the two states worth showing, but a college football
     * Saturday is followed by six days in which the most interesting thing a
     * scoreboard can say is what happened — and a blank panel where a score
     * was reads as broken rather than as "no games".
     */
    public const KEEP_FINAL_FOR = 21600; // six hours

    /**
     * How long the CHOICE of game is cached.
     *
     * 🚨 The choice, not the board. The scores are re-read every time so a
     * refresh is never serving a cached number; what is cached is the three
     * ordered lookups that decide which fixture the widget is about, because
     * that answer changes at kickoff and at the final whistle and not in
     * between. A homepage widget on a busy Saturday would otherwise run them
     * once per reader per poll.
     */
    public const PICK_FOR = 20;

    /** See DiscussionBoardField for why Picks is reached by name. */
    protected const EVENT = '\\Resofire\\Picks\\PickEvent';

    public function __construct(
        protected Scoreboard $scoreboard,
        protected Cache $cache
    ) {
    }

    /**
     * How many games a wide placement can hold.
     *
     * 🚨 A cap on the QUERY, not on the strip. A Saturday in September has
     * sixty fixtures and nobody scrolls sixty cards — but more to the point,
     * every one of them is a row serialised, a crest fetched and a thread
     * looked up for a reader who will see the first four.
     */
    public const MOST = 10;

    /**
     * The games worth showing, most relevant first.
     *
     * 🚨 A LIST, because the widget's shape is decided by where somebody put
     * it and not by this. In a sidebar it draws the first of these; given the
     * width of a page it draws a scrolling strip of them. Answering with one
     * game would make that a second request at a different URL, and the two
     * would drift.
     *
     * @return list<array<string, mixed>>
     */
    public function boards(User $actor, int $limit = self::MOST): array
    {
        /*
         * 🚨 Checked before anything queries `picks_events`. This extension is
         * useless without Picks but must not be the thing that fatals when
         * somebody disables it — and unlike the thread's own board, which is
         * only built where a game thread exists, this runs on any page a widget
         * was placed on.
         */
        if (! class_exists(self::EVENT)) {
            return [];
        }

        $limit = max(1, min($limit, self::MOST));

        $ids = $this->cache->remember(
            'gameday.current-games.' . $limit,
            self::PICK_FOR,
            // An empty array caches perfectly well; it is a null that does not,
            // which would run all three lookups per request out of season.
            fn () => $this->choose($limit)
        );

        if ($ids === []) {
            return [];
        }

        $model = self::EVENT;
        $events = $model::query()->with(['homeTeam', 'awayTeam'])->whereIn('id', $ids)->get()->keyBy('id');

        $threads = GamedayThread::query()->whereIn('event_id', $ids)->get()->keyBy('event_id');

        // 🚨 One visibility query for the whole strip. Resolved per thread this
        // was a `whereVisibleTo` per card — ten of them, each dragging the tag
        // scopes behind it, to draw one row of scores.
        $links = $this->links($threads->pluck('discussion_id')->all(), $actor);

        $out = [];

        // In the order chosen, not the order the database returned them.
        foreach ($ids as $id) {
            $event = $events[$id] ?? null;

            if ($event === null) {
                continue;
            }

            $thread = $threads[$id] ?? null;

            $board = $this->scoreboard->shape($event, $thread);
            $board['discussion'] = $thread === null
                ? null
                : ($links[(int) $thread->discussion_id] ?? null);

            $out[] = $board;
        }

        return $out;
    }

    /**
     * The single most relevant game, for a caller that only wants one.
     *
     * @return array<string, mixed>|null
     */
    public function board(User $actor): ?array
    {
        return $this->boards($actor, 1)[0] ?? null;
    }

    /**
     * The game the widget is about: being played, else next to kick off, else
     * the last one to finish.
     *
     * 🚨 Chosen from the FIXTURES, not from the game threads.
     *
     * An earlier version picked a thread and read its game, which meant a board
     * following a full season showed nothing at all until somebody opened a
     * thread — and Game Day only opens one a few hours before kickoff, if it is
     * switched on at all. fbsfb.com found this the honest way: 666 fixtures
     * ahead of it, 49 in the coming week, and a blank panel.
     *
     * A game being played is a fact about the game. The thread is where people
     * talk about it, which is a link this may or may not have — and `link()`
     * already withholds it from a reader who could not open it anyway.
     */
    protected function choose(int $limit): array
    {
        $now = date('Y-m-d H:i:s');

        /*
         * In progress by either account: the feed says so, or Game Day has put
         * its thread live. They usually agree; when they do not, the one that
         * thinks a game is on is the one worth believing, because the cost of
         * being wrong is a board that is a few minutes early rather than one
         * that misses the game.
         */
        $ids = $this->take(
            $limit,
            fn ($q) => $q
                ->where(function ($w) {
                    $w->where('picks_events.status', 'in_progress')
                      ->orWhere('gameday_threads.state', GamedayThread::LIVE);
                })
                ->orderBy('picks_events.match_date')
        );

        /*
         * 🚨 Topped up rather than replaced. Three games being played and seven
         * about to start is a scoreboard; an either/or would drop the seven the
         * moment one game kicked off, so a strip would shrink to a single card
         * on the busiest afternoon of the week.
         */
        if (count($ids) < $limit) {
            $ids = array_merge($ids, $this->take(
                $limit - count($ids),
                fn ($q) => $q
                    ->where('picks_events.match_date', '>', $now)
                    ->where('picks_events.status', '!=', 'finished')
                    ->orderBy('picks_events.match_date'),
                $ids
            ));
        }

        /*
         * Finals last, and only recent ones. They are the least interesting
         * thing a scoreboard can say — but on a quiet Sunday morning they are
         * the only thing it has, and a blank panel is worse.
         */
        if (count($ids) < $limit) {
            $ids = array_merge($ids, $this->take(
                $limit - count($ids),
                fn ($q) => $q
                    ->where('picks_events.status', 'finished')
                    ->where('picks_events.match_date', '>', date('Y-m-d H:i:s', time() - self::KEEP_FINAL_FOR))
                    ->orderByDesc('picks_events.match_date'),
                $ids
            ));
        }

        return $ids;
    }

    /**
     * Fixture ids, in the order asked for.
     *
     * 🚨 Aliased to a bare `id` in the select. Both joined tables have an `id`
     * column, so an unqualified select hands back the thread's id for the
     * fixture's — a wrong row that looks entirely plausible right up until the
     * widget shows the wrong game.
     *
     * @param  list<int> $exclude ids an earlier pass already took
     * @return list<int>
     */
    protected function take(int $limit, callable $narrow, array $exclude = []): array
    {
        if ($limit < 1) {
            return [];
        }

        $query = $this->candidates()->select('picks_events.id as id')->limit($limit);

        if ($exclude !== []) {
            $query->whereNotIn('picks_events.id', $exclude);
        }

        $narrow($query);

        /*
         * 🚨 Distinct on the ID. The thread join is a LEFT join and a fixture
         * with two thread rows — which the unique index makes unlikely rather
         * than impossible — would otherwise take two of the slots in the strip
         * and draw the same game twice.
         */
        return array_values(array_unique(array_map(
            fn ($row) => (int) $row->id,
            $query->get()->all()
        )));
    }

    /**
     * Fixtures, with whatever thread each one has.
     *
     * 🚨 LEFT joins throughout. A fixture nobody has opened a thread for is
     * still a game, and a thread whose discussion was deleted must not take its
     * fixture down with it — but a thread pointing at a deleted or hidden
     * discussion must not supply a link either, which is why the discussion is
     * joined here and checked again, per reader, in link().
     */
    protected function candidates(): \Illuminate\Database\Eloquent\Builder
    {
        $model = self::EVENT;

        return $model::query()
            ->leftJoin('gameday_threads', 'gameday_threads.event_id', '=', 'picks_events.id')
            ->leftJoin('discussions', function ($join) {
                $join->on('discussions.id', '=', 'gameday_threads.discussion_id')
                     ->whereNull('discussions.hidden_at');
            });
    }

    /**
     * Where to send a reader who wants each thread — or nothing.
     *
     * 🚨 The SCORE is public and the LINK is not. A game thread can sit in a
     * tag not everybody may read, and a widget that linked into it anyway would
     * be a dead end and, worse, a standing disclosure that the thread exists.
     * The score itself came from a public scoreboard and is nobody's secret.
     *
     * 🚨 Per actor, which is why it is resolved outside the cached pick. One
     * reader's visibility cached and served to the next is the whole failure
     * mode this comment exists to prevent.
     *
     * @param  list<int|string> $discussionIds
     * @return array<int, array<string, mixed>>
     */
    protected function links(array $discussionIds, User $actor): array
    {
        $discussionIds = array_values(array_unique(array_filter(array_map('intval', $discussionIds))));

        if ($discussionIds === []) {
            return [];
        }

        $out = [];

        foreach (
            Discussion::query()
                ->whereVisibleTo($actor)
                ->whereIn('id', $discussionIds)
                ->get(['id', 'slug', 'title', 'comment_count'])
            as $discussion
        ) {
            $out[(int) $discussion->id] = [
                'id' => (int) $discussion->id,
                'slug' => $discussion->slug,
                'title' => $discussion->title,
                'commentCount' => (int) $discussion->comment_count,
            ];
        }

        // A discussion this reader cannot see is simply absent from the map,
        // which is what makes its card render with no link at all.
        return $out;
    }
}
