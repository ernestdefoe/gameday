<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Api\Resource;

use ErnestDefoe\Gameday\Service\ScoreboardForDiscussion;
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
    public function __construct(protected ScoreboardForDiscussion $boards)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Arr::make('gamedayBoard')
                ->nullable()
                ->visible(fn ($discussion, $context) => $context->showing())
                ->get(fn ($discussion) => $this->boards->forDiscussion((int) $discussion->id)),
        ];
    }
}
