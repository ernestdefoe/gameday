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
                    $blocks[] = $this->bold($name).' — '.$line;
                }
            }

            $comparison = $this->comparison($stats, $home, $away);

            if ($comparison !== '') {
                $blocks[] = $comparison;
            }
        }

        /*
         * The detail the XenForo build carries, in the order a broadcast gives
         * it: how the game went period by period, the play that decided it,
         * every score, and what the market had made of it beforehand. Each is
         * omitted entirely when the box score does not carry it, so a game
         * stored before any of this was captured still reads exactly as it did.
         */
        foreach ([
            $this->lineScore($box, $home, $away, $homeScore, $awayScore),
            $this->turningPoint($box, $home, $away, $homeScore, $awayScore),
            $this->scoringSummary($box, $home, $away),
            $this->market($box, $home, $away, $homeScore, $awayScore),
        ] as $block) {
            if ($block !== '') {
                $blocks[] = $block;
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

    /**
     * The quarter-by-quarter line, the way a stadium board shows it.
     *
     * 🚨 As many periods as were actually PLAYED, not a hardcoded four. An
     * overtime game has five or more, and anything assuming four drops the
     * period the game was decided in — the one worth reading.
     *
     * 🚨 One line per side rather than a table. A Markdown table needs an
     * extension most boards do not have, and when it is missing the reader gets
     * a screenful of pipes; this reads correctly whatever is installed, and
     * better on a phone, which is where most of these are read.
     *
     * @param array<string, mixed>|null $box
     */
    protected function lineScore(?array $box, string $home, string $away, int $homeScore, int $awayScore): string
    {
        $lines = (array) ($box['linescores'] ?? []);
        $h = array_values((array) ($lines['home'] ?? []));
        $a = array_values((array) ($lines['away'] ?? []));

        $periods = max(count($h), count($a));

        if ($periods < 2) {
            return '';
        }

        $heads = [];

        for ($i = 0; $i < $periods; $i++) {
            $heads[] = $i < 4 ? $this->sport->periodName($i + 1) : 'OT'.($i - 3 > 1 ? $i - 3 : '');
        }

        $row = function (string $name, array $points, int $total) use ($periods, $heads): string {
            $parts = [];

            for ($i = 0; $i < $periods; $i++) {
                $parts[] = $heads[$i].' '.(int) ($points[$i] ?? 0);
            }

            return $this->bold($name).' — '.implode(' · ', $parts).' — final '.$total;
        };

        return implode("\n", [
            $this->bold('By the quarter'),
            $row($away, $a, $awayScore),
            $row($home, $h, $homeScore),
        ]);
    }

    /**
     * The play that moved the game most.
     *
     * 🚨 Measured, not chosen. It is the largest change in win probability
     * between two consecutive plays, which is what "the turning point" actually
     * means — rather than the longest touchdown, which is merely the loudest.
     *
     * @param array<string, mixed>|null $box
     */
    protected function turningPoint(?array $box, string $home, string $away, int $homeScore, int $awayScore): string
    {
        $swing = (array) ($box['swing'] ?? []);
        $text = trim((string) ($swing['text'] ?? ''));

        if ($text === '') {
            return '';
        }

        $gained = ($swing['toward'] ?? 'home') === 'home' ? $home : $away;
        $points = (int) ($swing['points'] ?? 0);

        if ($points < 5) {
            // 🚨 Nothing swung it. A game decided gradually has no turning
            // point, and naming one anyway would invent a story about it.
            return '';
        }

        /*
         * 🚨 "The turning point" only when the swing went the WINNER's way.
         *
         * The largest swing in a game is often a score by the side that went on
         * to lose — here a Coastal Carolina touchdown in a game Liberty won by
         * seventeen. Calling that the turning point tells the reader a story
         * about the game that did not happen; it was the biggest swing, and
         * saying so is both true and still worth reading.
         */
        $winner = $homeScore === $awayScore ? null : ($homeScore > $awayScore ? 'home' : 'away');
        $decisive = $winner !== null && ($swing['toward'] ?? '') === $winner;

        return implode("\n", [
            $this->bold($decisive ? 'The turning point' : 'The biggest swing'),
            $text,
            sprintf("That swung it %d points %s's way.", $points, $gained),
        ]);
    }

    /**
     * Every score, in order, with the board after it.
     *
     * 🚨 The running score comes from the feed rather than being added up here.
     * It states what the board read after each score; recomputing would
     * disagree with it the first time a two-point conversion or a safety
     * appeared, and disagree silently.
     *
     * @param array<string, mixed>|null $box
     */
    protected function scoringSummary(?array $box, string $home, string $away): string
    {
        $plays = (array) ($box['scoring'] ?? []);

        if ($plays === []) {
            return '';
        }

        $lines = [$this->bold('Scoring')];

        foreach ($plays as $play) {
            if (! is_array($play)) {
                continue;
            }

            $when = trim($this->sport->periodName((int) ($play['period'] ?? 0)).' '.(string) ($play['clock'] ?? ''));
            $what = trim((string) ($play['text'] ?? '')) ?: trim((string) ($play['type'] ?? ''));

            if ($what === '') {
                continue;
            }

            $lines[] = sprintf(
                '%s — %s (%s %d, %s %d)',
                $when,
                $what,
                $away,
                (int) ($play['away'] ?? 0),
                $home,
                (int) ($play['home'] ?? 0)
            );
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * What the market had made of it, and how that turned out.
     *
     * 🚨 The spread is read from the FAVOURITE's side, and which side that is
     * comes from the line itself rather than from who was at home. Assuming the
     * home team was favoured gets the cover backwards in every road-favourite
     * game, which is a third of the card on a given Saturday.
     *
     * @param array<string, mixed>|null $box
     */
    protected function market(?array $box, string $home, string $away, int $homeScore, int $awayScore): string
    {
        $market = (array) ($box['market'] ?? []);
        $line = trim((string) ($market['line'] ?? ''));

        if ($line === '') {
            return '';
        }

        $lines = [$this->bold('Against the line')];

        // "LIB -2.5" — the abbreviation names the favourite, the number the price.
        if (preg_match('/^(\S+)\s*([+-]?\d+(?:\.\d+)?)$/', $line, $m)) {
            $spread = abs((float) $m[2]);
            $sides = $this->sides($box);
            $homeAbbr = strtoupper((string) ($sides['home']['team'] ?? $home));
            $homeIsFavourite = str_starts_with($homeAbbr, strtoupper($m[1]))
                || str_starts_with(strtoupper($home), strtoupper($m[1]));

            $favourite = $homeIsFavourite ? $home : $away;
            $margin = $homeIsFavourite ? $homeScore - $awayScore : $awayScore - $homeScore;

            $lines[] = match (true) {
                $margin > $spread => sprintf('%s (%s) covered.', $favourite, $line),
                $margin < $spread => sprintf('%s (%s) did not cover.', $favourite, $line),
                default => sprintf('%s was a push.', $line),
            };
        } else {
            $lines[] = 'The line was '.$line.'.';
        }

        $total = $market['total'] ?? null;

        if ($total !== null) {
            $points = $homeScore + $awayScore;
            $lines[] = match (true) {
                $points > (float) $total => sprintf('%d points, over the total of %s.', $points, $total),
                $points < (float) $total => sprintf('%d points, under the total of %s.', $points, $total),
                default => sprintf('%d points, exactly the total.', $points),
            };
        }

        if (($market['provider'] ?? '') !== '') {
            // Named, because two books rarely agree and an unattributed number
            // reads as a fact of the universe.
            $lines[] = 'Line from '.$market['provider'].'.';
        }

        return implode("\n", $lines);
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

            if (! is_array($leader) || ($leader['name'] ?? '') === '') {
                continue;
            }

            $said = $this->sport->playerLine($category, (array) ($leader['stats'] ?? []));

            if ($said !== '') {
                $byPlayer[(string) $leader['name']][] = $said;
            }
        }

        $parts = [];

        foreach ($byPlayer as $name => $lines) {
            $parts[] = $name.' '.implode(', and ', $lines);
        }

        return $parts === [] ? '' : implode('; ', $parts).'.';
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
        array_unshift($lines, $this->bold($home.' / '.$away));

        return implode("\n", $lines);
    }

    /* -------------------------------------------------------------- plumbing */

    protected function bold(string $text): string
    {
        return match ($this->emphasis) {
            self::EMPHASIS_BBCODE => '[b]'.$text.'[/b]',
            self::EMPHASIS_MARKDOWN => '**'.$text.'**',
            default => $text,
        };
    }

    /**
     * @param  array<string, mixed>|null $box
     * @return array{home: array<string, mixed>, away: array<string, mixed>}|null
     */
    protected function sides(?array $box): ?array
    {
        if (! is_array($box) || ! isset($box['home'], $box['away'])) {
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
