<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service;

use ErnestDefoe\Gameday\GamedayThread;

/**
 * The board for a discussion, or null when that discussion is not a game.
 *
 * 🚨 One implementation, shared. The API field and the share-card driver both
 * need this, and two copies of "which game is this thread about" is two places
 * for the answer to drift — the panel once showed the same game on every live
 * thread for exactly that kind of reason.
 */
class ScoreboardForDiscussion
{
    public function __construct(protected Scoreboard $scoreboard)
    {
    }

    /** @return array<string, mixed>|null */
    public function forDiscussion(int $discussionId): ?array
    {
        $thread = GamedayThread::query()->where('discussion_id', $discussionId)->first();

        if ($thread === null) {
            return null;
        }

        $event = $this->event((int) $thread->event_id);

        return $event === null ? null : $this->scoreboard->shape($event, $thread);
    }

    /**
     * 🚨 Reached through the class name rather than a `use` at the top: this
     * extension is useless without Picks but must not be the thing that fatals
     * when somebody disables it.
     */
    protected function event(int $id): ?object
    {
        $model = '\\Resofire\\Picks\\PickEvent';

        if (! class_exists($model)) {
            return null;
        }

        return $model::query()->with(['homeTeam', 'awayTeam'])->find($id);
    }
}
