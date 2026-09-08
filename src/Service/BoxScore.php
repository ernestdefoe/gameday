<?php

namespace ErnestDefoe\Gameday\Service;

use Illuminate\Database\ConnectionInterface;

/**
 * The box score Picks has synced, read.
 *
 * 🚨 Read-only, and the only place here that knows Picks' table name. If Picks
 * ever renames something, this file is the blast radius rather than a service,
 * a recap and a command.
 *
 * 🚨 Nothing here reaches CollegeFootballData. Picks owns the provider, the API
 * key and the call budget; this reads what it left behind. A second place that
 * could make that call is a second place the budget is not enforced.
 */
class BoxScore
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    /** Whether Picks is new enough to be keeping box scores at all. */
    public function available(): bool
    {
        try {
            return $this->db->getSchemaBuilder()->hasTable('picks_box_scores');
        } catch (\Throwable) {
            /*
             * An older Picks has no such table, and that is a perfectly good
             * state to be in — the recap simply says the score, which is what
             * it said before any of this existed.
             */
            return false;
        }
    }

    /**
     * The normalised box score for a game, or null when there is none yet.
     *
     * @return array<string, mixed>|null
     */
    public function forEvent(int $eventId): ?array
    {
        if (!$this->available()) {
            return null;
        }

        $row = $this->db->table('picks_box_scores')->where('event_id', $eventId)->first();

        if ($row === null) {
            return null;
        }

        $document = json_decode((string) $row->payload, true);

        return is_array($document) ? $document : null;
    }
}
