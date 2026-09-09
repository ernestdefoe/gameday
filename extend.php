<?php

namespace ErnestDefoe\Gameday;

use ErnestDefoe\Gameday\Api\Controller\BoardController;
use ErnestDefoe\Gameday\Api\Controller\CurrentBoardController;
use ErnestDefoe\Gameday\Api\Controller\ReactionsController;
use ErnestDefoe\Gameday\Api\Controller\TeamTagsController;
use ErnestDefoe\Gameday\Api\Resource\DiscussionBoardField;
use ErnestDefoe\Gameday\Console\EnrichCommand;
use ErnestDefoe\Gameday\Console\TickCommand;
use ErnestDefoe\Gameday\Service\Settings;
use ErnestDefoe\Gameday\Service\Sports\Sports;
use Flarum\Extend;
use Flarum\Frontend\Document;

$extenders = [
    /*
     * 🚨 The sport list reaches the admin from the registry rather than being
     * written into the JavaScript. A second copy of the list in the bundle is
     * a copy that goes stale the first time an extension registers a league —
     * which is the whole reason the registry exists.
     */
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/resources/less/admin.less')
        ->content(function (Document $document): void {
            $document->payload['gamedaySports'] = (new Sports())->choices();
        }),

    /*
     * 🚨 The forum bundle exists for ONE thing: the scoreboard at the head of a
     * game thread. Everything else this extension does happens on a schedule,
     * with nothing on the page to show for it — which is why there was no forum
     * frontend here at all until the board needed one.
     */
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/resources/less/forum.less'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    // `gamedayBoard` on a game thread, and null on every other discussion.
    (new Extend\ApiResource(\Flarum\Api\Resource\DiscussionResource::class))
        ->fields(DiscussionBoardField::class),

    /*
     * 🚨 Defaults registered here rather than read with `?? 180` at every call
     * site. Two readers disagreeing about what an unset value means is how a
     * thread opens three hours early on one screen and on time on another.
     * `Service\Settings` is still the only thing that reads them.
     */
    (new Extend\Settings())
        ->default(Settings::PREFIX . 'enabled', false)
        ->default(Settings::PREFIX . 'author_id', 0)
        ->default(Settings::PREFIX . 'lead_minutes', 180)
        ->default(Settings::PREFIX . 'fallback_tag_id', 0)
        ->default(Settings::PREFIX . 'sport', Sports::DEFAULT)
        ->default(Settings::PREFIX . 'recaps', true)
        ->default(Settings::PREFIX . 'sticky_while_live', true)
        ->serializeToForum('gamedayEnabled', Settings::PREFIX . 'enabled', 'boolval'),

    (new Extend\Routes('api'))
        ->get('/gameday/team-tags', 'gameday.team-tags', TeamTagsController::class)
        ->post('/gameday/team-tags', 'gameday.team-tags.save', TeamTagsController::class)
        /*
         * What the board says right now. This is the whole of "live" — without
         * it a live scoreboard is a photograph of a live scoreboard.
         */
        ->get('/gameday/board/{id}', 'gameday.board', BoardController::class)
        /*
         * What is on right now, for a widget. A different question from the one
         * above — see CurrentBoardController.
         */
        ->get('/gameday/board', 'gameday.board.current', CurrentBoardController::class)
        /*
         * The roar. Ephemeral and cache-backed — see Service\LiveReactions for
         * why none of this is ever written to the database.
         */
        ->get('/gameday/reactions/{id}', 'gameday.reactions', ReactionsController::class)
        ->post('/gameday/reactions/{id}', 'gameday.reactions.push', ReactionsController::class),

    (new Extend\Console())
        ->command(TickCommand::class)
        ->command(EnrichCommand::class)
        /*
         * Every minute for the threads themselves: a thread that opens three
         * minutes late is a thread people were already waiting for.
         */
        ->schedule(TickCommand::class, function ($event) {
            $event->everyMinute()->withoutOverlapping();
        })
        /*
         * Hourly for the statistics, which arrive on their own timetable —
         * see EnrichCommand.
         */
        ->schedule(EnrichCommand::class, function ($event) {
            $event->hourly()->withoutOverlapping();
        }),
];

/*
 * The scoreboard as a Page Builder block — only where Page Builder is installed.
 *
 * 🚨 Guarded on the EXTENDER's class, not on the extension being enabled. This
 * file is read at boot, before anything knows which extensions are on, and
 * naming a class from an extension that is not installed is a fatal at compile
 * time rather than a missing block. The block class itself is never mentioned
 * outside this branch for the same reason: it extends a Page Builder base class
 * that would not be there to extend.
 *
 * Bespoke needs no counterpart — its widgets are registered entirely from the
 * JavaScript, through a queue it drains itself.
 */
if (class_exists(\Ernestdefoe\PageBuilder\Extend\PageBuilderBlock::class)) {
    $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(
        \ErnestDefoe\Gameday\Block\ScoreboardBlock::class
    );

    $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(
        \ErnestDefoe\Gameday\Block\PerformersBlock::class
    );
}

return $extenders;
