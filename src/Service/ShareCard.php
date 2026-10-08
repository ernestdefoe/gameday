<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service;

use Flarum\Foundation\Paths;
use GuzzleHttp\Client;

/**
 * The picture that appears when somebody shares a game thread.
 *
 * 🚨 This is acquisition work, not decoration. A link to a game thread posted in
 * a group chat or on a team subreddit previewed with the site's own logo —
 * identical for all 717 fixtures, and saying nothing about the game. A
 * scoreboard says what the link is and who it is for, which is the whole
 * difference between a link somebody clicks and one they scroll past.
 *
 * 🚨 Rendered once and cached on disk, keyed by the game's STATE. A scheduled
 * fixture, a live game and a final are three different pictures of the same
 * thread, and a card cached by id alone would freeze the first of them forever.
 */
class ShareCard
{
    public const WIDTH = 1200;
    public const HEIGHT = 630;

    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    private const FONT_REGULAR = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    public function __construct(protected Paths $paths)
    {
    }

    public function directory(): string
    {
        return $this->paths->public.'/assets/gameday-cards';
    }

    /**
     * The card's public path for a board, rendering it if it is not there yet,
     * or null when this server cannot draw one.
     *
     * @param array<string, mixed> $board
     */
    public function pathFor(int $discussionId, array $board): ?string
    {
        if (! function_exists('imagettftext') || ! is_readable(self::FONT)) {
            // 🚨 No font, no card. A card drawn with GD's built-in bitmap font
            // looks like a 1998 CGI counter, and a bad share image is worse for
            // a link than the site's own logo.
            return null;
        }

        $name = $discussionId.'-'.substr(sha1($this->stateKey($board)), 0, 10).'.png';
        $file = $this->directory().'/'.$name;

        if (! file_exists($file) && ! $this->render($board, $file)) {
            return null;
        }

        return '/assets/gameday-cards/'.$name;
    }

    /**
     * Everything that changes what the card should look like.
     *
     * @param array<string, mixed> $board
     */
    protected function stateKey(array $board): string
    {
        $home = (array) ($board['home'] ?? []);
        $away = (array) ($board['away'] ?? []);

        return implode('|', [
            (string) ($board['state'] ?? ''),
            (string) ($board['status'] ?? ''),
            (string) ($home['name'] ?? ''), (string) ($home['score'] ?? ''),
            (string) ($away['name'] ?? ''), (string) ($away['score'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $board */
    protected function render(array $board, string $file): bool
    {
        $dir = dirname($file);

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }

        $im = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        if ($im === false) {
            return false;
        }

        $ink = fn (int $r, int $g, int $b) => imagecolorallocate($im, $r, $g, $b);

        // A scoreboard's own palette: chrome, not the page's.
        $bg = $ink(13, 17, 23);
        $panel = $ink(22, 27, 34);
        $white = $ink(255, 255, 255);
        $muted = $ink(139, 148, 158);
        $accent = $ink(211, 47, 47);

        imagefilledrectangle($im, 0, 0, self::WIDTH, self::HEIGHT, $bg);
        imagefilledrectangle($im, 0, 0, self::WIDTH, 8, $accent);
        // 🚨 Deep enough to hold the scores. At HEIGHT-150 the 64pt numerals
        // overran the panel's bottom edge by about ten pixels and sat half on
        // the card's background — visible, and the sort of thing that reads as
        // carelessness in a picture whose whole job is to be looked at.
        imagefilledrectangle($im, 60, 120, self::WIDTH - 60, self::HEIGHT - 118, $panel);

        $home = (array) ($board['home'] ?? []);
        $away = (array) ($board['away'] ?? []);
        $final = ($board['state'] ?? '') === 'final';

        $this->side($im, $away, 300, $white, $muted, $final);
        $this->side($im, $home, 900, $white, $muted, $final);

        // The state, between them.
        $headline = $this->headline($board);
        $this->centred($im, $headline, 600, 330, 30, $final ? $white : $accent, self::FONT);

        $sub = trim((string) ($board['statusDetail'] ?? $board['status'] ?? ''));

        if ($sub !== '' && strcasecmp($sub, $headline) !== 0) {
            $this->centred($im, $sub, 600, 375, 18, $muted, self::FONT_REGULAR);
        }

        $venue = trim((string) ($board['venue'] ?? ''));

        if ($venue !== '') {
            $this->centred($im, $venue, 600, self::HEIGHT - 95, 20, $muted, self::FONT_REGULAR);
        }

        $this->centred($im, 'FBSFB.COM', 600, self::HEIGHT - 45, 20, $white, self::FONT);

        $ok = imagepng($im, $file, 6);
        imagedestroy($im);

        if ($ok) {
            @chmod($file, 0664);
        }

        return (bool) $ok;
    }

    /** @param array<string, mixed> $side */
    protected function side(\GdImage $im, array $side, int $x, int $white, int $muted, bool $final): void
    {
        $crest = $this->crest((string) ($side['logo'] ?? $side['logoLight'] ?? ''));

        if ($crest !== null) {
            $w = imagesx($crest);
            $h = imagesy($crest);
            $scale = 150 / max($w, $h);
            $dw = (int) ($w * $scale);
            $dh = (int) ($h * $scale);
            imagecopyresampled($im, $crest, $x - (int) ($dw / 2), 175, 0, 0, $dw, $dh, $w, $h);
            imagedestroy($crest);
        }

        $name = trim((string) ($side['name'] ?? ''));
        $this->centred($im, $this->fit($name, 18), $x, 370, 26, $white, self::FONT);

        $record = trim((string) ($side['record'] ?? ''));
        $rank = $side['rank'] ?? null;
        $under = trim(($rank ? '#'.$rank.'  ' : '').$record);

        if ($under !== '') {
            $this->centred($im, $under, $x, 405, 18, $muted, self::FONT_REGULAR);
        }

        $score = $side['score'] ?? null;

        if ($score !== null && $score !== '') {
            $this->centred($im, (string) $score, $x, 490, 64, $white, self::FONT);
        }
    }

    /** @param array<string, mixed> $board */
    protected function headline(array $board): string
    {
        return match ((string) ($board['state'] ?? '')) {
            'final' => 'FINAL',
            'live' => 'LIVE',
            default => 'KICKOFF',
        };
    }

    /**
     * A crest, fetched once and kept.
     *
     * 🚨 Cached per URL, so a season's worth of cards costs at most one request
     * per club rather than two per card.
     *
     * 🚨 The URL comes from the team table, which anyone holding the Picks
     * "manage" permission can edit, and the result is saved under public/. So
     * the fetch is guarded like any user-supplied URL: https only, ports 443,
     * the host must resolve to public addresses only (and curl is pinned to
     * those, so DNS cannot change its mind), redirects are not followed, the
     * body is cut off at 2 MB while it streams, and only something GD can
     * decode as an image is kept, re-encoded as PNG. Nothing else ever lands
     * in the public crest folder.
     */
    protected function crest(string $url): ?\GdImage
    {
        if ($url === '' || ! preg_match('~^https://~i', $url)) {
            return null;
        }

        $cache = $this->directory().'/crests';
        $file = $cache.'/'.sha1($url).'.png';

        if (! file_exists($file)) {
            if (! is_dir($cache) && ! @mkdir($cache, 0775, true) && ! is_dir($cache)) {
                return null;
            }

            $bytes = $this->fetchPublic($url, 2 * 1024 * 1024);
            $im = $bytes !== null ? @imagecreatefromstring($bytes) : false;

            if (! $im) {
                return null;
            }

            imagesavealpha($im, true);
            imagepng($im, $file);
            @chmod($file, 0664);

            return $im;
        }

        $im = @imagecreatefromstring((string) file_get_contents($file));

        return $im ?: null;
    }

    /** The body of an https URL on a public host, or null. */
    protected function fetchPublic(string $url, int $maxBytes): ?string
    {
        $parts = parse_url($url);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = (int) ($parts['port'] ?? 443);

        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || $port !== 443 || isset($parts['user'])) {
            return null;
        }

        $ips = self::publicIps($host);
        if ($ips === null) {
            return null;
        }

        try {
            $response = (new Client())->get($url, [
                'timeout' => 8,
                'connect_timeout' => 4,
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
                'curl' => [
                    CURLOPT_RESOLVE => [$host.':443:'.implode(',', $ips)],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $body = $response->getBody();
            $bytes = '';
            while (! $body->eof()) {
                $bytes .= $body->read(65536);
                if (strlen($bytes) > $maxBytes) {
                    return null;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $bytes === '' ? null : $bytes;
    }

    /**
     * Every address $host resolves to, or null if any is not public.
     *
     * An IP written as the host is accepted only as a plain dotted quad or a
     * bracketed IPv6 address: octal, hex and short forms are read differently
     * by different resolvers, and curl dials what it reads.
     *
     * @return list<string>|null
     */
    public static function publicIps(string $host): ?array
    {
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ips = [$bare];
        } elseif (preg_match('/^(?:\d+|0x[0-9a-f]*)(?:\.(?:\d*|0x[0-9a-f]*))*$/i', $host)) {
            if (! preg_match('/^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/', $host)) {
                return null;
            }
            $ips = [$host];
        } else {
            $ips = [];
            foreach ((array) @dns_get_record($host, DNS_A + DNS_AAAA) as $r) {
                if (! empty($r['ip'])) {
                    $ips[] = $r['ip'];
                }
                if (! empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return null;
            }
        }

        return array_values(array_unique($ips));
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // 100.64.0.0/10 (carrier-grade NAT) is not covered by PHP's filters.
            if ((ip2long($ip) & 0xFFC00000) === 0x64400000) {
                return false;
            }

            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        $hex = bin2hex($packed);
        if (str_starts_with($hex, '00000000000000000000ffff')) {
            return self::isPublicIp((string) long2ip((int) hexdec(substr($hex, 24, 8))));
        }
        if (preg_match('/^0{31}[01]$/', $hex)) {
            return false; // :: and ::1
        }
        $first = hexdec(substr($hex, 0, 4));
        if (($first & 0xfe00) === 0xfc00 || ($first & 0xff00) === 0xff00 || ($first & 0xffc0) === 0xfe80) {
            return false; // ULA, multicast, link-local
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** Centred text, measured rather than guessed at. */
    protected function centred(\GdImage $im, string $text, int $cx, int $y, int $size, int $colour, string $font): void
    {
        if ($text === '') {
            return;
        }

        $box = imagettfbbox($size, 0, $font, $text);
        $width = abs($box[4] - $box[0]);
        imagettftext($im, $size, 0, $cx - (int) ($width / 2), $y, $colour, $font, $text);
    }

    /** 🚨 Truncated, because a long club name otherwise runs into the other one. */
    protected function fit(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)).'…';
    }
}
