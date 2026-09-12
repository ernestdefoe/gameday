<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Pull fresh numbers from the feed on the way to drawing the board.
 *
 * The scheduler is the floor, not the ceiling. `schedule:run` is driven by a
 * minutely timer, so nothing it runs can ever be fresher than sixty seconds —
 * fine for a final score, wrong for a game clock, which is the one number on
 * the board that is visibly incorrect the moment it is late.
 *
 * The board is already asked for every few seconds by anyone reading the
 * thread. That request is the opportunity: if the numbers behind it have gone
 * stale, refresh them first and answer with the new ones.
 *
 * 🚨 The refresh is shared, not per-viewer. `Cache::add()` is atomic — it
 * writes only if the key is absent — so exactly ONE request in each window
 * performs the fetch and every other one returns immediately and serves what
 * is already there. A thread with four hundred people on it makes the same
 * number of outbound calls as a thread with one: at most one per window.
 * Without that, "refresh on read" is a way to point a crowd at someone else's
 * API, which is how a scheduled job took this stack down once before.
 */
class LiveRefresh
{
    /**
     * How long a set of numbers is considered fresh.
     *
     * With the browser asking every ~4.5s, this puts the worst case a viewer
     * can see at roughly TTL + one poll. Twelve seconds is chosen to be
     * comfortably inside "the clock on my screen matches the clock on the TV"
     * while still being six times cheaper than the every-second fetch that
     * would actually be needed to keep a running clock exact.
     */
    public const TTL = 12;

    private const KEY = 'gameday.live-refresh';

    /** Reached by name for the same reason the controllers reach PickEvent that way. */
    private const SYNC = '\\Resofire\\Picks\\Service\\SyncScoresService';

    private const EVENT = '\\Resofire\\Picks\\PickEvent';

    public function __construct(protected Cache $cache)
    {
    }

    /**
     * Refresh the live feed if nobody has recently, and never complain.
     *
     * Called on the read path, so every failure mode here has to end with the
     * board still being drawn: a feed that is down, a Picks version without the
     * service, a cache that refuses to lock. The board showing numbers a minute
     * old is a much better outcome than the board not showing.
     */
    public function nudge(): void
    {
        if (! class_exists(self::SYNC) || ! class_exists(self::EVENT)) {
            return;
        }

        /*
         * Nothing in progress means nothing to refresh, and no reason to touch
         * the feed at all. This check runs on every board request, so it is one
         * indexed existence query — deliberately cheaper than the fetch it
         * avoids, which is the whole of the day when no game is on.
         */
        if (! $this->anythingLive()) {
            return;
        }

        // Atomic: the winner refreshes, everyone else is already served.
        if (! $this->cache->add(self::KEY, 1, self::TTL)) {
            return;
        }

        try {
            resolve(self::SYNC)->syncFromEspn();
        } catch (\Throwable $e) {
            // Swallowed on purpose. The scheduled run is what is expected to
            // surface a persistent feed problem, with the status code the
            // service now carries; this path exists only to be fresher, and
            // must never be the reason a page fails to render.
        }
    }

    protected function anythingLive(): bool
    {
        $model = self::EVENT;

        try {
            return $model::query()->where('status', 'in_progress')->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
