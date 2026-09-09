<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Api\Resource;

use ErnestDefoe\Gameday\GamedayThread;
use ErnestDefoe\Gameday\Service\Scoreboard;
use Flarum\Api\Schema;

/**
 * `gamedayBoard` on a discussion that is a game thread, and null on every
 * other discussion.
 *
 * 🚨 On the discussion itself rather than fetched by the component after the
 * page loads. A scoreboard that appears a beat after the thread does is a
 * scoreboard that shoves the first post down the page while somebody is
 * already reading it — and the board is the reason they opened the thread, so
 * it should be there when the thread is. Polling takes over afterwards.
 *
 * 🚨 Only on a SHOW. A discussion list serialises fifty discussions, and none
 * of them draws a board; loading each one's game there would be fifty joins for
 * something nothing renders.
 */
class DiscussionBoardField
{
    public function __construct(protected Scoreboard $scoreboard)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Arr::make('gamedayBoard')
                ->nullable()
                ->visible(fn ($discussion, $context) => $context->showing())
                ->get(function ($discussion) {
                    $thread = GamedayThread::query()
                        ->where('discussion_id', $discussion->id)
                        ->first();

                    if ($thread === null) {
                        return null;
                    }

                    $event = $this->event($thread->event_id);

                    return $event === null ? null : $this->scoreboard->shape($event, $thread);
                }),
        ];
    }

    /**
     * The fixture, with both clubs.
     *
     * 🚨 Reached through the class name rather than a `use` at the top: this
     * extension is useless without Picks but must not be the thing that fatals
     * when somebody disables it. An absent class is a null board and a thread
     * that renders perfectly well without a scoreboard on it.
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
