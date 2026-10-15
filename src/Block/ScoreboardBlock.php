<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Block;

use ErnestDefoe\Gameday\Service\CurrentGame;
use ErnestDefoe\Gameday\Service\Words;
use Ernestdefoe\PageBuilder\Block\AbstractBlock;
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
        return Words::t('api.scoreboard_block.name');
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
                'label' => Words::t('api.scoreboard_block.field_title'),
                'default' => Words::t('api.scoreboard_block.default_title'),
                'help' => Words::t('api.scoreboard_block.field_title_help'),
            ],
            [
                'key' => 'showLink',
                'type' => 'toggle',
                'label' => Words::t('api.scoreboard_block.field_link'),
                'default' => true,
                'help' => Words::t('api.scoreboard_block.field_link_help'),
            ],
            [
                'key' => 'hideWhenEmpty',
                'type' => 'toggle',
                'label' => Words::t('api.scoreboard_block.field_hide'),
                'default' => true,
                'help' => Words::t('api.scoreboard_block.field_hide_help'),
            ],
            [
                'key' => 'autoScroll',
                'type' => 'toggle',
                'label' => Words::t('api.scoreboard_block.field_scroll'),
                'default' => true,
                'help' => Words::t('api.scoreboard_block.field_scroll_help'),
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
        $boards = $this->current->boards($actor);

        return ['boards' => $boards, 'board' => $boards[0] ?? null];
    }
}
