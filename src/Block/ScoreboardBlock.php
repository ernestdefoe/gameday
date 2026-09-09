<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Block;

use Ernestdefoe\PageBuilder\Block\AbstractBlock;
use ErnestDefoe\Gameday\Service\CurrentGame;
use Flarum\User\User;

/**
 * The scoreboard as a Page Builder block.
 *
 * 🚨 This class is only ever loaded when Page Builder is installed — the
 * extender that registers it is added conditionally in extend.php, and nothing
 * else names it. It extends a class from an extension this one does not require,
 * which is safe for exactly that reason and dangerous the moment anything else
 * refers to it.
 */
class ScoreboardBlock extends AbstractBlock
{
    public function __construct(protected CurrentGame $current)
    {
    }

    public function type(): string
    {
        return 'gameday-scoreboard';
    }

    public function name(): string
    {
        return 'Scoreboard';
    }

    public function icon(): string
    {
        return 'fas fa-football';
    }

    public function category(): string
    {
        return 'forum';
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'title',
                'type' => 'text',
                'label' => 'Title',
                'default' => 'Game Day',
                'help' => 'Leave empty for no heading — the board says what it is.',
            ],
            [
                'key' => 'showLink',
                'type' => 'toggle',
                'label' => 'Link to the game thread',
                'default' => true,
                'help' => 'The link is dropped anyway for a reader who cannot open the thread.',
            ],
            [
                'key' => 'hideWhenEmpty',
                'type' => 'toggle',
                'label' => 'Hide when there is no game',
                'default' => true,
                'help' => 'Otherwise the block says so. Off-season, that is a panel saying nothing every day for months.',
            ],
        ];
    }

    /**
     * 🚨 Resolved server-side so the board is drawn with the page rather than a
     * request later. A widget that appears a beat after everything else shoves
     * the content under it down the screen while somebody is already reading —
     * and unlike the thread's own board, this one is often above the fold.
     *
     * 🚨 Scoped to the actor, because the LINK is. CurrentGame drops it for a
     * reader who cannot open the thread; caching this per page rather than per
     * reader is how that protection would be undone.
     */
    public function resolve(array $settings, User $actor): array
    {
        return ['board' => $this->current->board($actor)];
    }
}
