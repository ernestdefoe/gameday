<?php

namespace ErnestDefoe\Gameday;

use Flarum\Database\AbstractModel;

/**
 * Which tag a team's games are posted in.
 *
 * @property int $id
 * @property int $team_id
 * @property int $tag_id
 */
class TeamTag extends AbstractModel
{
    public $timestamps = true;

    protected $table = 'gameday_team_tags';

    protected $fillable = ['team_id', 'tag_id'];

    protected $casts = ['team_id' => 'integer', 'tag_id' => 'integer'];
}
