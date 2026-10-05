<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service;

/**
 * Where a game is on, and the one link that gets somebody there.
 *
 * 🚨 No video, ever. Live rights are sold per market and per platform and
 * wrapped in DRM; a forum cannot embed a live game and should not pretend to.
 * What it can do is say which channel has it and open that channel's own player
 * in one tap — which is the question everybody in a game thread asks first.
 *
 * 🚨 Pure. The listings come from Picks, which reads them out of the scoreboard
 * payload it already fetches; nothing here touches the network or the database,
 * so the board, the widget and the tests all get the same answer.
 *
 * 🚨 Never claims a game is FREE. `subscription` is set only for services that
 * are nothing but a subscription (ESPN+, Peacock, Prime Video...). A broadcast
 * channel is left unmarked either way: whether somebody can see ESPN depends on
 * their cable bundle, and the feed does not know that any more than we do.
 */
class WhereToWatch
{
    /** Shown first, in this order: what most of the board can actually receive. */
    private const MARKET_ORDER = ['national' => 0, 'home' => 1, 'away' => 2];

    private const KIND_ORDER = ['tv' => 0, 'streaming' => 1, 'radio' => 2];

    private const ESPN_WATCH = 'https://www.espn.com/watch/';

    /**
     * The networks we know, keyed by a folded name (upper case, letters and
     * digits only) with ESPN's own spellings as aliases.
     *
     * 🚨 Every URL below was requested on 2026-10-05 and answered (or, for
     * ESPN's, answered behind its bot check and opened in a browser). A network
     * whose live page could not be confirmed gets NO link rather than a guessed
     * one — a chip that says where the game is beats a button to a 404.
     *
     * `family` groups the networks ESPN's own player carries, which is what
     * decides whether the feed's event watch link applies to a chip.
     *
     * @var array<string, array{label: string, family: string, url: string, subscription?: bool, kind?: string}>
     */
    private const NETWORKS = [
        'ESPN' => ['label' => 'ESPN', 'family' => 'espn', 'url' => self::ESPN_WATCH],
        'ESPN2' => ['label' => 'ESPN2', 'family' => 'espn', 'url' => self::ESPN_WATCH],
        'ESPNU' => ['label' => 'ESPNU', 'family' => 'espn', 'url' => self::ESPN_WATCH],
        'ESPNEWS' => ['label' => 'ESPNEWS', 'family' => 'espn', 'url' => self::ESPN_WATCH],
        'ESPNDEPORTES' => ['label' => 'ESPN Deportes', 'family' => 'espn', 'url' => self::ESPN_WATCH],
        'ESPNPLUS' => ['label' => 'ESPN+', 'family' => 'espn', 'url' => self::ESPN_WATCH, 'subscription' => true, 'kind' => 'streaming'],
        'ABC' => ['label' => 'ABC', 'family' => 'espn', 'url' => self::ESPN_WATCH],
        'SECNETWORK' => ['label' => 'SEC Network', 'family' => 'espn', 'url' => self::ESPN_WATCH],
        'SECN' => ['label' => 'SEC Network', 'family' => 'espn', 'url' => self::ESPN_WATCH],
        'SECNETWORKPLUS' => ['label' => 'SEC Network+', 'family' => 'espn', 'url' => self::ESPN_WATCH, 'kind' => 'streaming'],
        'ACCNETWORK' => ['label' => 'ACC Network', 'family' => 'espn', 'url' => self::ESPN_WATCH],
        'ACCN' => ['label' => 'ACC Network', 'family' => 'espn', 'url' => self::ESPN_WATCH],
        'ACCNX' => ['label' => 'ACCNX', 'family' => 'espn', 'url' => self::ESPN_WATCH, 'kind' => 'streaming'],
        'LONGHORNNETWORK' => ['label' => 'Longhorn Network', 'family' => 'espn', 'url' => self::ESPN_WATCH],
        'DISNEYPLUS' => ['label' => 'Disney+', 'family' => 'disney', 'url' => 'https://www.disneyplus.com/', 'subscription' => true, 'kind' => 'streaming'],

        'FOX' => ['label' => 'FOX', 'family' => 'fox', 'url' => 'https://www.foxsports.com/live'],
        'FS1' => ['label' => 'FS1', 'family' => 'fox', 'url' => 'https://www.foxsports.com/live/fs1'],
        'FS2' => ['label' => 'FS2', 'family' => 'fox', 'url' => 'https://www.foxsports.com/live/fs2'],
        'BTN' => ['label' => 'Big Ten Network', 'family' => 'fox', 'url' => 'https://www.foxsports.com/live/btn'],
        'BIGTENNETWORK' => ['label' => 'Big Ten Network', 'family' => 'fox', 'url' => 'https://www.foxsports.com/live/btn'],
        'FOXONE' => ['label' => 'Fox One', 'family' => 'fox', 'url' => 'https://www.foxsports.com/live', 'subscription' => true, 'kind' => 'streaming'],

        'CBS' => ['label' => 'CBS', 'family' => 'cbs', 'url' => 'https://www.cbssports.com/watch/live'],
        'CBSSN' => ['label' => 'CBS Sports Network', 'family' => 'cbs', 'url' => 'https://www.cbssports.com/watch/cbs-sports-network'],
        'CBSSPORTSNETWORK' => ['label' => 'CBS Sports Network', 'family' => 'cbs', 'url' => 'https://www.cbssports.com/watch/cbs-sports-network'],
        'PARAMOUNTPLUS' => ['label' => 'Paramount+', 'family' => 'cbs', 'url' => 'https://www.paramountplus.com/', 'subscription' => true, 'kind' => 'streaming'],

        'NBC' => ['label' => 'NBC', 'family' => 'nbc', 'url' => 'https://www.peacocktv.com/'],
        'PEACOCK' => ['label' => 'Peacock', 'family' => 'nbc', 'url' => 'https://www.peacocktv.com/', 'subscription' => true, 'kind' => 'streaming'],
        'USANET' => ['label' => 'USA Network', 'family' => 'usa', 'url' => 'https://www.usanetwork.com/live'],
        'USANETWORK' => ['label' => 'USA Network', 'family' => 'usa', 'url' => 'https://www.usanetwork.com/live'],

        'CW' => ['label' => 'The CW', 'family' => 'cw', 'url' => 'https://www.cwtv.com/sports/'],
        'THECW' => ['label' => 'The CW', 'family' => 'cw', 'url' => 'https://www.cwtv.com/sports/'],

        'TNT' => ['label' => 'TNT', 'family' => 'tnt', 'url' => 'https://www.tntsports.com/'],
        'TBS' => ['label' => 'TBS', 'family' => 'tnt', 'url' => 'https://www.tntsports.com/'],
        'TRUTV' => ['label' => 'truTV', 'family' => 'tnt', 'url' => 'https://www.tntsports.com/'],
        'HBOMAX' => ['label' => 'HBO Max', 'family' => 'tnt', 'url' => 'https://www.hbomax.com/', 'subscription' => true, 'kind' => 'streaming'],
        'MAX' => ['label' => 'HBO Max', 'family' => 'tnt', 'url' => 'https://www.hbomax.com/', 'subscription' => true, 'kind' => 'streaming'],

        'PRIMEVIDEO' => ['label' => 'Prime Video', 'family' => 'prime', 'url' => 'https://www.amazon.com/primevideo', 'subscription' => true, 'kind' => 'streaming'],
        'NETFLIX' => ['label' => 'Netflix', 'family' => 'netflix', 'url' => 'https://www.netflix.com/', 'subscription' => true, 'kind' => 'streaming'],
        'APPLETV' => ['label' => 'Apple TV', 'family' => 'apple', 'url' => 'https://tv.apple.com/', 'subscription' => true, 'kind' => 'streaming'],

        'NFLNET' => ['label' => 'NFL Network', 'family' => 'nfl', 'url' => 'https://www.nfl.com/network/watch'],
        'NFLNETWORK' => ['label' => 'NFL Network', 'family' => 'nfl', 'url' => 'https://www.nfl.com/network/watch'],
        'NBATV' => ['label' => 'NBA TV', 'family' => 'nba', 'url' => 'https://www.nba.com/watch'],
        'MLBNETWORK' => ['label' => 'MLB Network', 'family' => 'mlb', 'url' => 'https://www.mlb.com/network'],
        'MLBNET' => ['label' => 'MLB Network', 'family' => 'mlb', 'url' => 'https://www.mlb.com/network'],
        'NHLNET' => ['label' => 'NHL Network', 'family' => 'nhl', 'url' => ''],
    ];

    /**
     * The block a board draws, or null when there is nothing to say.
     *
     * @param  array<string, mixed>|null $stored  Picks' `broadcasts` column, decoded
     * @param  string $legacy  Picks' single `broadcast` name, for a Picks that predates the listing
     * @param  string $state   scheduled | live | final
     * @return array{channels: list<array<string, mixed>>, watch: array{url: string, label: string, subscription: bool}|null, compact: bool}|null
     */
    public static function build(?array $stored, string $legacy, string $state, string $homeAbbr = '', string $awayAbbr = ''): ?array
    {
        $listings = array_values(array_filter((array) ($stored['listings'] ?? []), 'is_array'));

        /*
         * 🚨 A Picks without the listing still knows the national channel, so
         * that is drawn rather than nothing. "ESPN / ESPN+" splits into two.
         */
        if ($listings === [] && trim($legacy) !== '') {
            foreach (preg_split('#\s*[/,|]\s*#', trim($legacy)) ?: [] as $name) {
                $listings[] = ['name' => $name, 'type' => '', 'market' => 'national'];
            }
        }

        $channels = [];

        foreach ($listings as $listing) {
            $channel = self::channel((string) ($listing['name'] ?? ''), (string) ($listing['type'] ?? ''), (string) ($listing['market'] ?? ''), $homeAbbr, $awayAbbr);

            if ($channel !== null) {
                $channels[$channel['label'] . '|' . $channel['market']] ??= $channel;
            }
        }

        if ($channels === []) {
            return null;
        }

        $channels = array_values($channels);

        usort($channels, fn (array $a, array $b): int => [self::MARKET_ORDER[$a['market']], self::KIND_ORDER[$a['kind']]]
            <=> [self::MARKET_ORDER[$b['market']], self::KIND_ORDER[$b['kind']]]);

        // After the final whistle there is nothing left to watch live.
        if ($state === 'final') {
            return ['channels' => $channels, 'watch' => null, 'compact' => true];
        }

        return ['channels' => $channels, 'watch' => self::watch($channels, (string) ($stored['watch'] ?? '')), 'compact' => false];
    }

    /**
     * One chip.
     *
     * @return array{key: string, label: string, kind: string, market: string, team: string, subscription: bool, url: string, family: string}|null
     */
    public static function channel(string $name, string $type, string $market, string $homeAbbr = '', string $awayAbbr = ''): ?array
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $key = self::fold($name);
        $known = self::NETWORKS[$key] ?? null;

        $kind = match (strtolower(trim($type))) {
            'streaming' => 'streaming',
            'radio' => 'radio',
            'tv' => 'tv',
            // No medium said: what the network is, or television.
            default => $known['kind'] ?? 'tv',
        };

        $market = in_array($market, ['home', 'away'], true) ? $market : 'national';

        return [
            'key' => strtolower($key),
            'label' => $known['label'] ?? $name,
            'kind' => $kind,
            'market' => $market,
            'team' => $market === 'home' ? $homeAbbr : ($market === 'away' ? $awayAbbr : ''),
            'subscription' => (bool) ($known['subscription'] ?? false),
            // Radio is listed, never linked: the Watch button is for watching.
            'url' => $kind === 'radio' ? '' : (string) ($known['url'] ?? ''),
            'family' => (string) ($known['family'] ?? ''),
        ];
    }

    /**
     * The one button: the first national channel that can be opened, else the
     * first local one.
     *
     * 🚨 An ESPN-family channel uses the feed's own event watch link when Picks
     * kept one — that opens THIS game in ESPN's player rather than the front of
     * ESPN's watch section. It is only ever an https espn.com link; Picks
     * refuses anything else before it is stored, and it is checked again here.
     *
     * @param list<array<string, mixed>> $channels
     * @return array{url: string, label: string, subscription: bool}|null
     */
    public static function watch(array $channels, string $eventLink = ''): ?array
    {
        $candidates = array_values(array_filter($channels, fn (array $c): bool => $c['url'] !== ''));

        if ($candidates === []) {
            return null;
        }

        $pick = $candidates[0];
        $url = $pick['url'];

        if ($pick['family'] === 'espn' && self::isEspnWatchUrl($eventLink)) {
            $url = $eventLink;
        }

        return ['url' => $url, 'label' => $pick['label'], 'subscription' => $pick['subscription']];
    }

    public static function isEspnWatchUrl(string $href): bool
    {
        $parts = parse_url($href);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($host === 'espn.com' || str_ends_with($host, '.espn.com'))
            && str_contains((string) ($parts['path'] ?? ''), '/watch');
    }

    /** "ESPN+" → ESPNPLUS, "SEC Network" → SECNETWORK, "Prime Video (Local)" → PRIMEVIDEOLOCAL. */
    public static function fold(string $name): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', str_replace('+', 'PLUS', strtoupper($name)));
    }
}
