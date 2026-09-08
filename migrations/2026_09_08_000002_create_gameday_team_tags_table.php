<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Which tag a team's games are posted in.
 *
 * 🚨 A table of its own rather than a column on `picks_teams`, for the reason
 * the threads table gives: Picks owns that schema. It also means a site with no
 * mapping at all still works — every game goes to the fallback tag — and that
 * removing this extension leaves Picks exactly as it was.
 *
 * One tag per team, and a tag may hold several teams: a board with one
 * "College Football" tag maps everybody to it, and a board with a tag per
 * programme maps one each. Both are ordinary.
 */
return Migration::createTableIfNotExists(
    'gameday_team_tags',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('team_id')->unique();
        $table->unsignedInteger('tag_id');
        $table->timestamps();
    }
);
