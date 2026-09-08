<?php

namespace ErnestDefoe\Gameday\Service;

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
 */
class Recap
{
    public const EMPHASIS_NONE = 'none';
    public const EMPHASIS_BBCODE = 'bbcode';
    public const EMPHASIS_MARKDOWN = 'markdown';

    /**
     * The comparison, in the order somebody reads a game: how far each side
     * moved the ball, how they moved it, then the things that decide close
     * ones. Possession is last because it is the least explanatory number on
     * the list and the one most often mistaken for one that matters.
     */
    private const TABLE = [
        'firstDowns' => 'First downs',
        'totalYards' => 'Total yards',
        'netPassingYards' => 'Passing yards',
        'rushingYards' => 'Rushing yards',
        'thirdDownEff' => 'Third down',
        'fourthDownEff' => 'Fourth down',
        'totalPenaltiesYards' => 'Penalties',
        'turnovers' => 'Turnovers',
        'possessionTime' => 'Possession',
    ];

    public function __construct(
        protected string $emphasis = self::EMPHASIS_NONE
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
            $this->outcome($home, $away, $homeScore, $awayScore),
        ];

        $stats = $this->sides($box);

        if ($stats !== null) {
            $said = $this->howItWent($stats, $home, $away);

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

    protected function outcome(string $home, string $away, int $homeScore, int $awayScore): string
    {
        if ($homeScore === $awayScore) {
            return 'It finished level.';
        }

        $winner = $homeScore > $awayScore ? $home : $away;
        $margin = abs($homeScore - $awayScore);

        /*
         * 🚨 The margin is described, not just stated. "Won it" is equally true
         * of a one-point game and a fifty-point one, which makes it worth
         * nothing in either.
         */
        return match (true) {
            $margin <= 3 => $winner . ' took it by ' . $margin . '.',
            $margin >= 28 => $winner . ' were never troubled.',
            $margin >= 17 => $winner . ' had it comfortably.',
            default => $winner . ' won it by ' . $margin . '.',
        };
    }

    /**
     * 🚨 Each sentence is earned. A yardage line only when the two are far
     * enough apart to mean something, a turnover line only when somebody
     * actually lost the ball. A recap that always has three sentences has three
     * sentences of nothing on the day nothing happened.
     *
     * @param  array{home: array<string, mixed>, away: array<string, mixed>} $sides
     * @return array<int, string>
     */
    protected function howItWent(array $sides, string $home, string $away): array
    {
        $out = [];

        $homeYards = $this->number($sides['home']['stats']['totalYards'] ?? null);
        $awayYards = $this->number($sides['away']['stats']['totalYards'] ?? null);

        if ($homeYards !== null && $awayYards !== null) {
            [$leader, $trailer, $more, $fewer] = $homeYards >= $awayYards
                ? [$home, $away, $homeYards, $awayYards]
                : [$away, $home, $awayYards, $homeYards];

            $out[] = $more - $fewer < 40
                // Two teams within forty yards did not win it there, and saying
                // one "out-gained" the other implies they did.
                ? sprintf('There was almost nothing in the yardage — %d to %d.', $more, $fewer)
                : sprintf('%s out-gained %s %d to %d.', $leader, $trailer, $more, $fewer);
        }

        $homeAway = $this->number($sides['home']['stats']['turnovers'] ?? null);
        $awayAway = $this->number($sides['away']['stats']['turnovers'] ?? null);

        if ($homeAway !== null && $awayAway !== null && $homeAway + $awayAway > 0) {
            $out[] = $homeAway === $awayAway
                ? sprintf('They gave it away %s each.', $this->times($homeAway))
                : sprintf(
                    '%s gave it away %s, %s %s.',
                    $homeAway > $awayAway ? $home : $away,
                    $this->times(max($homeAway, $awayAway)),
                    $homeAway > $awayAway ? $away : $home,
                    min($homeAway, $awayAway) === 0 ? 'not at all' : $this->times(min($homeAway, $awayAway)),
                );
        }

        return $out;
    }

    protected function times(int $n): string
    {
        return match ($n) {
            0 => 'not at all',
            1 => 'once',
            2 => 'twice',
            default => $n . ' times',
        };
    }

    /**
     * 🚨 Its own wording rather than `times()`. "With once picked off" is what
     * counting words give you when they are reused for something that is not a
     * count of occasions, and it reads as a typo.
     */
    protected function picks(int $n): string
    {
        return match (true) {
            $n < 1 => '',
            $n === 1 => ', with an interception',
            default => ', with ' . $this->word($n) . ' interceptions',
        };
    }

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

        foreach (['passing', 'rushing', 'receiving'] as $category) {
            $leader = $leaders[$category] ?? null;

            if (!is_array($leader) || ($leader['name'] ?? '') === '') {
                continue;
            }

            $said = $this->player($category, (array) ($leader['stats'] ?? []));

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

    /** @param array<string, string> $stats */
    protected function player(string $category, array $stats): string
    {
        $yards = $this->number($stats['YDS'] ?? null);

        if ($yards === null) {
            return '';
        }

        $touchdowns = $this->number($stats['TD'] ?? null) ?? 0;
        $scores = match (true) {
            $touchdowns < 1 => '',
            $touchdowns === 1 => ' and a touchdown',
            default => ' and ' . $this->word($touchdowns) . ' touchdowns',
        };

        return match ($category) {
            'passing' => sprintf(
                '%s for %d%s%s',
                (string) ($stats['C/ATT'] ?? ''),
                $yards,
                $scores,
                $this->picks($this->number($stats['INT'] ?? null) ?? 0),
            ),
            'rushing' => sprintf('%s for %d%s', $this->carries($this->number($stats['CAR'] ?? null)), $yards, $scores),
            'receiving' => sprintf('%s for %d%s', $this->catches($this->number($stats['REC'] ?? null)), $yards, $scores),
            default => '',
        };
    }

    protected function carries(?int $n): string
    {
        return $n === null ? 'ran' : ($n === 1 ? 'one carry' : $n . ' carries');
    }

    protected function catches(?int $n): string
    {
        return $n === null ? 'caught' : ($n === 1 ? 'one catch' : $n . ' catches');
    }

    protected function word(int $n): string
    {
        return match ($n) {
            2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
            default => (string) $n,
        };
    }

    /* -------------------------------------------------------- the comparison */

    /** @param array{home: array<string, mixed>, away: array<string, mixed>} $sides */
    protected function comparison(array $sides, string $home, string $away): string
    {
        $lines = [];

        foreach (self::TABLE as $key => $label) {
            $homeValue = trim((string) ($sides['home']['stats'][$key] ?? ''));
            $awayValue = trim((string) ($sides['away']['stats'][$key] ?? ''));

            // A line neither side has a figure for is not a line.
            if ($homeValue === '' && $awayValue === '') {
                continue;
            }

            $lines[] = sprintf(
                '%s — %s / %s',
                $label,
                $homeValue === '' ? '—' : $homeValue,
                $awayValue === '' ? '—' : $awayValue,
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

    /** A figure the feed wrote as a string, when it really is a number. */
    protected function number($value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
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
