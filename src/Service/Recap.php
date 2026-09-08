<?php

namespace ErnestDefoe\Gameday\Service;

use ErnestDefoe\Gameday\Service\Sports\Gridiron;
use ErnestDefoe\Gameday\Service\Sports\Sport;

/**
 * The post a game thread ends with.
 *
 * 🚨 A pure function of the game and its box score, returning post text. No
 * database, no clock, no settings — so what it writes can be read in a test
 * rather than inferred from a post somewhere.
 *
 * 🚨 This is the SIBLING of `Services/Recap.php` in the Convoro build, and the
 * sentences below are deliberately the same words. What differs is only how
 * they are marked up: Convoro posts a structured document and can draw a real
 * table; a Flarum post is text that a formatter parses, and which formatter is
 * installed differs from site to site.
 *
 * 🚨 So the markup is asked for rather than assumed. A recap written in
 * Markdown on a board with no Markdown extension shows literal `**` to every
 * reader — that has happened here before — and one written in BBCode on a board
 * without it shows `[b]`. The emphasis style is decided by the caller from what
 * is actually enabled, and `none` is a first-class answer that reads perfectly
 * well.
 *
 * 🚨 And no table. Convoro's recap draws one because it can; here a table would
 * need a Markdown extension with table support, which most boards do not have,
 * and the failure mode is a screenful of pipes. One statistic per line reads
 * better on a phone anyway, which is where most of these are read.
 *
 * 🚨 The STRUCTURE lives here and the WORDS live in the sport, exactly as on the
 * Convoro side. Every game has a score, a result, a sentence or two on how it
 * went, the players worth naming and a comparison; that a team out-gains another
 * by 350 yards or has 62% of the ball is `Sports\Sport`, and it is why adding a
 * league is a class of sentences rather than a second `Recap`.
 */
class Recap
{
    public const EMPHASIS_NONE = 'none';
    public const EMPHASIS_BBCODE = 'bbcode';
    public const EMPHASIS_MARKDOWN = 'markdown';

    public function __construct(
        protected string $emphasis = self::EMPHASIS_NONE,
        protected Sport $sport = new Gridiron()
    ) {
    }

    /**
     * @param  array{home_name: string, away_name: string, home_score: int, away_score: int} $game
     * @param  array<string, mixed>|null $box
     */
    public function text(array $game, ?array $box = null): string
    {
        $home = (string) ($game['home_name'] ?? '');
        $away = (string) ($game['away_name'] ?? '');
        $homeScore = (int) ($game['home_score'] ?? 0);
        $awayScore = (int) ($game['away_score'] ?? 0);

        $blocks = [
            $this->bold(sprintf('Final: %s %d, %s %d.', $home, $homeScore, $away, $awayScore)),
            $this->sport->outcome($home, $away, $homeScore, $awayScore),
        ];

        $stats = $this->sides($box);

        if ($stats !== null) {
            $said = $this->sport->narrative($stats, $home, $away);

            if ($said !== []) {
                $blocks[] = implode(' ', $said);
            }

            foreach ([['home', $home], ['away', $away]] as [$side, $name]) {
                $line = $this->leaderLine($stats[$side]['leaders'] ?? []);

                if ($line !== '') {
                    $blocks[] = $this->bold($name) . ' — ' . $line;
                }
            }

            $comparison = $this->comparison($stats, $home, $away);

            if ($comparison !== '') {
                $blocks[] = $comparison;
            }
        }

        /*
         * 🚨 Last, and always. The sentence that tells somebody the thread is
         * not closed — the most common question under a finished game thread on
         * any forum that has ever had one.
         */
        $blocks[] = 'The thread is an ordinary topic again now — still here, still searchable.';

        return implode("\n\n", $blocks);
    }

    /** Whether a box score has enough in it to say anything with. */
    public function usable(?array $box): bool
    {
        return $this->sides($box) !== null;
    }

    /* ------------------------------------------------------------- the prose */

    /** @param array<string, array{name: string, stats: array<string, string>}> $leaders */
    protected function leaderLine(array $leaders): string
    {
        /*
         * 🚨 Grouped by PLAYER, not listed by category. A dual-threat
         * quarterback leads both passing and rushing, which is ordinary in
         * college football and read as though he were two people:
         *
         *   Demond Williams Jr. 24/35 for 268 and a touchdown;
         *   Demond Williams Jr. 7 carries for 61 and a touchdown
         *
         * Found on a real game the day this shipped. Same fix as the Convoro
         * build's — these two files are siblings.
         */
        $byPlayer = [];

        foreach (array_keys($this->sport->leaderCategories()) as $category) {
            $leader = $leaders[$category] ?? null;

            if (!is_array($leader) || ($leader['name'] ?? '') === '') {
                continue;
            }

            $said = $this->sport->playerLine($category, (array) ($leader['stats'] ?? []));

            if ($said !== '') {
                $byPlayer[(string) $leader['name']][] = $said;
            }
        }

        $parts = [];

        foreach ($byPlayer as $name => $lines) {
            $parts[] = $name . ' ' . implode(', and ', $lines);
        }

        return $parts === [] ? '' : implode('; ', $parts) . '.';
    }

    /* -------------------------------------------------------- the comparison */

    /** @param array{home: array<string, mixed>, away: array<string, mixed>} $sides */
    protected function comparison(array $sides, string $home, string $away): string
    {
        $lines = [];

        foreach ($this->sport->comparison() as $key => $label) {
            $homeValue = trim((string) ($sides['home']['stats'][$key] ?? ''));
            $awayValue = trim((string) ($sides['away']['stats'][$key] ?? ''));

            // A line neither side has a figure for is not a line.
            if ($homeValue === '' && $awayValue === '') {
                continue;
            }

            $lines[] = sprintf(
                '%s — %s / %s',
                $label,
                $homeValue === '' ? '—' : $this->sport->formatStat($key, $homeValue),
                $awayValue === '' ? '—' : $this->sport->formatStat($key, $awayValue),
            );
        }

        if ($lines === []) {
            return '';
        }

        // The heading says which column is which, once, rather than repeating
        // both names on every line.
        array_unshift($lines, $this->bold($home . ' / ' . $away));

        return implode("\n", $lines);
    }

    /* -------------------------------------------------------------- plumbing */

    protected function bold(string $text): string
    {
        return match ($this->emphasis) {
            self::EMPHASIS_BBCODE => '[b]' . $text . '[/b]',
            self::EMPHASIS_MARKDOWN => '**' . $text . '**',
            default => $text,
        };
    }


    /**
     * @param  array<string, mixed>|null $box
     * @return array{home: array<string, mixed>, away: array<string, mixed>}|null
     */
    protected function sides(?array $box): ?array
    {
        if (!is_array($box) || !isset($box['home'], $box['away'])) {
            return null;
        }

        $home = is_array($box['home']) ? $box['home'] : [];
        $away = is_array($box['away']) ? $box['away'] : [];

        if (($home['stats'] ?? []) === [] && ($away['stats'] ?? []) === []) {
            return null;
        }

        return ['home' => $home, 'away' => $away];
    }
}
