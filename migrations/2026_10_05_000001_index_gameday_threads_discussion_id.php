<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * 🚨 Every lookup of a thread by its discussion was a full table scan.
 *
 * The board on every discussion page, its fifteen-second poll and the
 * three-second reactions poll all ask `where discussion_id = ?` — on every
 * discussion, game thread or not — and the table gains a row per game, every
 * season. Through the schema builder so a table prefix is applied.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('gameday_threads', function (Blueprint $table) {
            $table->index('discussion_id', 'gameday_threads_discussion_id_index');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('gameday_threads', function (Blueprint $table) {
            $table->dropIndex('gameday_threads_discussion_id_index');
        });
    },
];
