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
     * @return array<string, mixed>|null
     */
    public function board(User $actor): ?array
    {
        /*
         * 🚨 Checked before anything queries `picks_events`. This extension is
         * useless without Picks but must not be the thing that fatals when
         * somebody disables it — and unlike the thread's own board, which is
         * only built where a game thread exists, this runs on any page a widget
         * was placed on.
         */
        if (! class_exists(self::EVENT)) {
            return null;
        }

        $eventId = $this->cache->remember(
            'gameday.current-game',
            self::PICK_FOR,
            // 0 rather than null: a cached null is not a cache hit, so an
            // out-of-season forum would run all three lookups on every request
            // precisely because there was nothing to find.
            fn () => $this->choose() ?? 0
        );

        if (! $eventId) {
            return null;
        }

        $event = $this->event($eventId);

        if ($event === null) {
            return null;
        }

        $thread = GamedayThread::query()->where('event_id', $eventId)->first();

        $board = $this->scoreboard->shape($event, $thread);
        $board['discussion'] = $thread === null ? null : $this->link($thread, $actor);

        return $board;
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
    protected function choose(): ?int
    {
        $now = date('Y-m-d H:i:s');

        /*
         * In progress by either account: the feed says so, or Game Day has put
         * its thread live. They usually agree; when they do not, the one that
         * thinks a game is on is the one worth believing, because the cost of
         * being wrong is a board that is a few minutes early rather than one
         * that misses the game.
         */
        $live = $this->first(
            fn ($q) => $q
                ->where(function ($w) {
                    $w->where('picks_events.status', 'in_progress')
                      ->orWhere('gameday_threads.state', GamedayThread::LIVE);
                })
                ->orderBy('picks_events.match_date')
        );

        if ($live !== null) {
            return $live;
        }

        $next = $this->first(
            fn ($q) => $q
                ->where('picks_events.match_date', '>', $now)
                ->where('picks_events.status', '!=', 'finished')
                ->orderBy('picks_events.match_date')
        );

        if ($next !== null) {
            return $next;
        }

        return $this->first(
            fn ($q) => $q
                ->where('picks_events.status', 'finished')
                ->where('picks_events.match_date', '>', date('Y-m-d H:i:s', time() - self::KEEP_FINAL_FOR))
                ->orderByDesc('picks_events.match_date')
        );
    }

    /**
     * One fixture id, or null.
     *
     * 🚨 Aliased to a bare `id` in the select. Both joined tables have an `id`
     * column, so an unqualified select hands back the thread's id for the
     * fixture's — a wrong row that looks entirely plausible right up until the
     * widget shows the wrong game.
     */
    protected function first(callable $narrow): ?int
    {
        $query = $this->candidates()->select('picks_events.id as id');

        $narrow($query);

        $row = $query->first();

        return $row === null ? null : (int) $row->id;
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
     * Where to send a reader who wants the thread — or nothing.
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
     * @return array<string, mixed>|null
     */
    protected function link(GamedayThread $thread, User $actor): ?array
    {
        $discussion = Discussion::query()
            ->whereVisibleTo($actor)
            ->find($thread->discussion_id);

        if ($discussion === null) {
            return null;
        }

        return [
            'id' => (int) $discussion->id,
            'slug' => $discussion->slug,
            'title' => $discussion->title,
            'commentCount' => (int) $discussion->comment_count,
        ];
    }

    /** See DiscussionBoardField for why Picks is reached by name. */
    protected function event(int $id): ?object
    {
        $model = self::EVENT;

        if (! class_exists($model)) {
            return null;
        }

        return $model::query()->with(['homeTeam', 'awayTeam'])->find($id);
    }
}
