<?php

namespace ErnestDefoe\Gameday;

use ErnestDefoe\Gameday\Api\Controller\TeamTagsController;
use ErnestDefoe\Gameday\Console\EnrichCommand;
use ErnestDefoe\Gameday\Console\TickCommand;
use ErnestDefoe\Gameday\Service\Settings;
use ErnestDefoe\Gameday\Service\Sports\Sports;
use Flarum\Extend;
use Flarum\Frontend\Document;

return [
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

    new Extend\Locales(__DIR__ . '/resources/locale'),

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
        ->post('/gameday/team-tags', 'gameday.team-tags.save', TeamTagsController::class),

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
