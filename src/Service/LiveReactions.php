<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * The reactions people throw at a game while it is being played.
 *
 * 🚨 These are NOT post reactions and are never written to the database. A post
 * reaction is a considered thing that belongs to a post and outlives the day; a
 * roar when somebody scores is worth exactly the ten seconds it is on screen,
 * and a table of half a million of them is a table nobody will ever query. They
 * live in the cache with a TTL and expire on their own.
 *
 * 🚨 Which also means they can be LOST — a cache flush takes them and nothing
 * is broken by that. Anything that must survive a restart does not belong here.
 */
class LiveReactions
{
    /**
     * How long a burst is worth showing.
     *
     * Long enough that somebody opening the thread mid-roar sees it, short
     * enough that they are not shown a celebration from two minutes ago as
     * though it were happening now.
     */
    public const WINDOW = 20;

    /**
     * 🚨 A hard ceiling on what one poll can return, so a stadium moment cannot
     * turn into a thousand DOM nodes on somebody's phone. Past this the count
     * is still true — the client is told how many there really were and draws a
     * number — but only this many actually fly.
     */
    public const MAX_PER_POLL = 60;

    /** What a reader may throw in one window; past it the extras are dropped. */
    public const PER_USER_LIMIT = 12;

    /** The emoji a board offers. Anything else is refused, not stored. */
    public const ALLOWED = ['🔥', '😱', '🙌', '😤', '💀', '🎉'];

    public function __construct(protected Cache $cache)
    {
    }

    /**
     * Record one reaction. Returns false when the reader is over their limit.
     */
    public function push(int $discussionId, int $userId, string $emoji): bool
    {
        if (! in_array($emoji, self::ALLOWED, true)) {
            return false;
        }

        $rate = 'gameday.rx.rate.'.$discussionId.'.'.$userId;
        $used = (int) $this->cache->get($rate, 0);

        if ($used >= self::PER_USER_LIMIT) {
            return false;
        }

        $this->cache->put($rate, $used + 1, self::WINDOW);

        $key = $this->key($discussionId);
        $all = $this->prune((array) $this->cache->get($key, []));
        $all[] = ['e' => $emoji, 'at' => microtime(true)];

        /*
         * 🚨 Trimmed on WRITE as well as read. Without this a busy thread's
         * entry grows for the whole window and every reader deserialises the
         * lot on every poll — the cost of a roar would land on the people
         * watching quietly.
         */
        if (count($all) > self::MAX_PER_POLL * 4) {
            $all = array_slice($all, -(self::MAX_PER_POLL * 4));
        }

        $this->cache->put($key, $all, self::WINDOW + 5);

        return true;
    }

    /**
     * Everything thrown since `$since`, plus the clock to ask from next time.
     *
     * @return array{reactions: list<array{e: string, at: float}>, truncated: int, now: float}
     */
    public function since(int $discussionId, float $since): array
    {
        $all = $this->prune((array) $this->cache->get($this->key($discussionId), []));

        $fresh = array_values(array_filter($all, fn ($r) => ($r['at'] ?? 0) > $since));
        $total = count($fresh);

        return [
            'reactions' => array_slice($fresh, -self::MAX_PER_POLL),
            // How many were dropped, so the client can say "and 240 more"
            // rather than quietly under-reporting the moment.
            'truncated' => max(0, $total - self::MAX_PER_POLL),
            /*
             * 🚨 The SERVER's clock, handed back for the next poll. A client
             * asking "since my own last timestamp" is asking with a clock that
             * may be minutes off the server's, and it would either replay the
             * same reactions forever or never see any.
             */
            'now' => microtime(true),
        ];
    }

    /** @param list<array{e: string, at: float}> $all */
    protected function prune(array $all): array
    {
        $cut = microtime(true) - self::WINDOW;

        return array_values(array_filter($all, fn ($r) => ($r['at'] ?? 0) > $cut));
    }

    protected function key(int $discussionId): string
    {
        return 'gameday.rx.'.$discussionId;
    }
}
