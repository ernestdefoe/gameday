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
        $threadId = $this->cache->remember(
            'gameday.current-thread',
            self::PICK_FOR,
            // 0 rather than null: a cached null is not a cache hit, so an
            // out-of-season forum would run all three lookups on every request
            // precisely because there was nothing to find.
            fn () => $this->choose() ?? 0
        );

        if (! $threadId) {
            return null;
        }

        $thread = GamedayThread::query()->find($threadId);
        $event = $thread === null ? null : $this->event($thread->event_id);

        if ($event === null) {
            return null;
        }

        $board = $this->scoreboard->shape($event, $thread);
        $board['discussion'] = $this->link($thread, $actor);

        return $board;
    }

    /**
     * The thread whose game the widget is about: live, else next, else the last
     * one to finish.
     */
    protected function choose(): ?int
    {
        $now = time();

        return $this->first(
            fn ($q) => $q->where('gameday_threads.state', GamedayThread::LIVE)
                ->orderBy('picks_events.match_date')
        ) ?? $this->first(
            fn ($q) => $q->where('gameday_threads.state', GamedayThread::OPEN)
                ->where('picks_events.match_date', '>', date('Y-m-d H:i:s', $now))
                ->orderBy('picks_events.match_date')
        ) ?? $this->first(
            fn ($q) => $q->where('gameday_threads.state', GamedayThread::RESOLVED)
                ->where('picks_events.match_date', '>', date('Y-m-d H:i:s', $now - self::KEEP_FINAL_FOR))
                ->orderByDesc('picks_events.match_date')
        );
    }

    /**
     * One thread id, or null.
     *
     * 🚨 Aliased to a bare `id` in the select. Two of the joined tables have an
     * `id` column, so an unqualified select hands back the fixture's id for the
     * thread's — a wrong row that looks entirely plausible right up until the
     * widget shows a game nobody is discussing.
     */
    protected function first(callable $narrow): ?int
    {
        $query = $this->candidates()->select('gameday_threads.id as id');

        $narrow($query);

        $row = $query->first();

        return $row === null ? null : (int) $row->id;
    }

    /**
     * Threads with both a fixture and a discussion still standing.
     *
     * 🚨 Joined to `discussions` so a deleted or hidden thread cannot be chosen.
     * Without it the widget goes on pointing at a thread nobody can open, and
     * because the pick is cached it does so for everybody at once.
     */
    protected function candidates(): \Illuminate\Database\Eloquent\Builder
    {
        return GamedayThread::query()
            ->join('picks_events', 'picks_events.id', '=', 'gameday_threads.event_id')
            ->join('discussions', 'discussions.id', '=', 'gameday_threads.discussion_id')
            ->whereNull('discussions.hidden_at');
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
        $model = '\\Resofire\\Picks\\PickEvent';

        if (! class_exists($model)) {
            return null;
        }

        return $model::query()->with(['homeTeam', 'awayTeam'])->find($id);
    }
}
