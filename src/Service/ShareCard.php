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
    protected function side($im, array $side, int $x, int $white, int $muted, bool $final): void
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
     * per club rather than two per card. The source is this site's own team
     * table, not anything a visitor supplies.
     */
    protected function crest(string $url)
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

            try {
                $bytes = (string) (new Client())->get($url, ['timeout' => 8])->getBody();
            } catch (\Throwable $e) {
                return null;
            }

            if ($bytes === '' || strlen($bytes) > 2 * 1024 * 1024) {
                return null;
            }

            file_put_contents($file, $bytes);
            @chmod($file, 0664);
        }

        $im = @imagecreatefromstring((string) file_get_contents($file));

        return $im ?: null;
    }

    /** Centred text, measured rather than guessed at. */
    protected function centred($im, string $text, int $cx, int $y, int $size, int $colour, string $font): void
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
