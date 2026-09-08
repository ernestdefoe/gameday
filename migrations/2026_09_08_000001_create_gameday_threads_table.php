<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One row per game whose thread exists.
 *
 * 🚨 A map rather than a column on `picks_events`, because Picks owns that
 * table and an extension writing into another extension's schema is how two
 * features end up unable to be uninstalled independently. This table can be
 * dropped and Picks does not notice.
 *
 * 🚨 `event_id` is UNIQUE, and that is the whole idempotency story. The tick
 * runs on a schedule and may overlap itself; a check-then-insert would open a
 * second discussion the first time it did.
 *
 * 🚨 No foreign key to `discussions` either. A moderator deleting a game thread
 * is a normal thing to do, and cascading from it would delete the row that
 * remembers the thread was already opened — so the next tick would open
 * another one.
 */
return Migration::createTableIfNotExists(
    'gameday_threads',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('event_id')->unique();
        $table->unsignedInteger('discussion_id');

        // 'open' before kickoff, 'live' during, 'resolved' after the recap.
        $table->string('state', 20)->default('open');

        /*
         * The recap, so a second final-whistle tick adds nothing. Null until
         * the game ends; a game that ends while the site is down still gets
         * one on the next tick, which is why this is a column rather than an
         * assumption about timing.
         */
        $table->unsignedInteger('recap_post_id')->nullable();

        /*
         * 🚨 When the recap stopped being just the score.
         *
         * A recap is posted the moment a game settles and the box score does
         * not exist yet — the provider publishes it minutes to hours later.
         * Waiting would delay the one thing everybody in the thread is waiting
         * for; posting twice would be noise. So the recap goes up at once and
         * is rewritten in place when the statistics arrive, and this is how a
         * pass over resolved threads knows which are still waiting.
         */
        $table->dateTime('stats_at')->nullable();

        $table->dateTime('opened_at')->nullable();
        $table->dateTime('live_at')->nullable();
        $table->dateTime('resolved_at')->nullable();
        $table->timestamps();

        $table->index('state');
    }
);
