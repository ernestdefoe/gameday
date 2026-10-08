<?php

namespace ErnestDefoe\Gameday;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The thread a game has.
 *
 * @property int         $id
 * @property int         $event_id
 * @property int         $discussion_id
 * @property string      $state
 * @property int|null    $recap_post_id
 * @property \Carbon\Carbon|null $stats_at
 * @property \Carbon\Carbon|null $opened_at
 * @property \Carbon\Carbon|null $live_at
 * @property \Carbon\Carbon|null $resolved_at
 */
class GamedayThread extends AbstractModel
{
    public const OPEN = 'open';
    public const LIVE = 'live';
    public const RESOLVED = 'resolved';

    public $timestamps = true;

    protected $table = 'gameday_threads';

    protected $fillable = [
        'event_id',
        'discussion_id',
        'state',
        'recap_post_id',
        'stats_at',
        'opened_at',
        'live_at',
        'resolved_at',
    ];

    protected $casts = [
        'stats_at' => 'datetime',
        'opened_at' => 'datetime',
        'live_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class, 'discussion_id');
    }
}
