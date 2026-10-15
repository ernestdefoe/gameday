<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service\Sports;

/**
 * Baseball.
 *
 * 🚨 The statistic keys are PREFIXED, and that is not decoration. ESPN answers
 * a baseball team's statistics as GROUPS — batting, pitching, fielding — and
 * `hits` appears in all three meaning three different things: hits made, hits
 * allowed, and hits handled in the field. Flattening them onto bare names would
 * silently pick whichever group came last and print a pitcher's line as the
 * batting figure. The provider prefixes them; this reads them prefixed.
 *
 * 🚨 Baseball is the sport where the box score is the story. A one-run game and
 * a ten-run game are the same sport played differently, and the sentences below
 * are about how runs were manufactured rather than about a margin, because in
 * baseball the margin says the least of any sport here.
 */
class Diamond extends Sport
{
    protected function periodAbbreviation(): string
    {
        return $this->t('diamond.period_abbr');
    }

    protected function periodsInRegulation(): int
    {
        return 9;
    }

    public function key(): string
    {
        return 'diamond';
    }

    public function kickoff(): string
    {
        return $this->t('diamond.kickoff');
    }

    public function name(): string
    {
        return 'Baseball';
    }

    public function comparison(): array
    {
        return [
            'batting.hits' => $this->t('diamond.stats.hits'),
            'batting.runs' => $this->t('diamond.stats.runs'),
            'batting.homeRuns' => $this->t('diamond.stats.home_runs'),
            'batting.RBIs' => $this->t('diamond.stats.rbi'),
            'batting.walks' => $this->t('diamond.stats.walks'),
            'batting.strikeouts' => $this->t('diamond.stats.strikeouts'),
            'batting.runnersLeftOnBase' => $this->t('diamond.stats.left_on_base'),
            'pitching.strikeouts' => $this->t('diamond.stats.pitching_strikeouts'),
            'pitching.earnedRuns' => $this->t('diamond.stats.earned_runs'),
            'fielding.errors' => $this->t('diamond.stats.errors'),
        ];
    }

    public function narrative(array $sides, string $home, string $away): array
    {
        $said = [];

        $homeHits = $this->number($sides['home']['stats']['batting.hits'] ?? null);
        $awayHits = $this->number($sides['away']['stats']['batting.hits'] ?? null);

        /*
         * 🚨 The hit count is only worth a sentence when it disagrees with the
         * score, and that disagreement is the most baseball thing there is: a
         * team out-hits another and loses, because hits are not runs. Where the
         * two agree, the score has already said it.
         */
        if ($homeHits !== null && $awayHits !== null && abs($homeHits - $awayHits) >= 3) {
            $moreHits = $homeHits > $awayHits ? $home : $away;
            $fewerHits = $homeHits > $awayHits ? $away : $home;

            $homeRuns = $this->number($sides['home']['stats']['batting.runs'] ?? null);
            $awayRuns = $this->number($sides['away']['stats']['batting.runs'] ?? null);
            $winnerByRuns = ($homeRuns !== null && $awayRuns !== null && $homeRuns !== $awayRuns)
                ? ($homeRuns > $awayRuns ? $home : $away)
                : null;

            if ($winnerByRuns !== null && $winnerByRuns === $fewerHits) {
                $said[] = $this->t('diamond.outhit_lost', [
                    'more' => $moreHits,
                    'fewer' => $fewerHits,
                    'moreHits' => (string) max($homeHits, $awayHits),
                    'fewerHits' => (string) min($homeHits, $awayHits),
                ]);
            } else {
                $said[] = $this->t('diamond.outhit', [
                    'more' => $moreHits,
                    'fewer' => $fewerHits,
                    'moreHits' => (string) max($homeHits, $awayHits),
                    'fewerHits' => (string) min($homeHits, $awayHits),
                ]);
            }
        }

        // Home runs, when somebody hit one. A game with none needs no sentence.
        $homeHr = $this->number($sides['home']['stats']['batting.homeRuns'] ?? null);
        $awayHr = $this->number($sides['away']['stats']['batting.homeRuns'] ?? null);

        if (($homeHr ?? 0) + ($awayHr ?? 0) > 0) {
            $said[] = match (true) {
                ($homeHr ?? 0) > 0 && ($awayHr ?? 0) > 0 => $this->t('diamond.deep_both', [
                    'times' => $this->times((int) $homeHr + (int) $awayHr),
                    'home' => $home,
                    'homeHr' => (string) (int) $homeHr,
                    'away' => $away,
                    'awayHr' => (string) (int) $awayHr,
                ]),
                ($homeHr ?? 0) > 0 => $this->t('diamond.deep', ['team' => $home, 'times' => $this->times((int) $homeHr)]),
                default => $this->t('diamond.deep', ['team' => $away, 'times' => $this->times((int) $awayHr)]),
            };
        }

        /*
         * 🚨 Errors, only when there were some. A clean game is the ordinary
         * case and a recap that reports "0 errors" every night has taught its
         * readers to skip the line by the second week.
         */
        $homeErr = $this->number($sides['home']['stats']['fielding.errors'] ?? null);
        $awayErr = $this->number($sides['away']['stats']['fielding.errors'] ?? null);

        if (($homeErr ?? 0) + ($awayErr ?? 0) >= 2) {
            $said[] = $this->t('diamond.errors', [
                'home' => $home,
                'homeTimes' => $this->times((int) $homeErr),
                'away' => $away,
                'awayTimes' => $this->times((int) $awayErr),
            ]);
        }

        return $said;
    }

    public function leaderCategories(): array
    {
        /*
         * 🚨 ESPN's baseball player box score is two groups — batting and
         * pitching — and they want different figures, which is exactly what a
         * category is for. Who "led" the batting is who drove in the most runs
         * rather than who got the most hits, because that is the line anybody
         * would quote.
         */
        return [
            'batting' => 'RBI',
            'pitching' => 'K',
        ];
    }

    public function playerLine(string $category, array $stats): string
    {
        if ($category === 'pitching') {
            $innings = trim((string) ($stats['IP'] ?? ''));
            $strikeouts = $this->number($stats['K'] ?? null);
            $earned = $this->number($stats['ER'] ?? null);

            if ($innings === '') {
                return '';
            }

            $line = $this->t('diamond.innings', ['ip' => $innings]);

            if ($earned !== null) {
                $line .= ', '.($earned === 0 ? $this->t('diamond.no_earned') : $this->t('diamond.earned', ['n' => (string) $earned]));
            }

            if ($strikeouts !== null && $strikeouts > 0) {
                $line .= ', '.$this->t('diamond.struck_out', ['n' => (string) $strikeouts]);
            }

            return $line;
        }

        // Batting. ESPN writes the day's line as `H-AB`, e.g. `2-4`.
        $line = trim((string) ($stats['H-AB'] ?? ''));

        if ($line === '') {
            return '';
        }

        $rbi = $this->number($stats['RBI'] ?? null);
        $homeRuns = $this->number($stats['HR'] ?? null);
        $hits = $this->number(explode('-', $line, 2)[0]);

        /*
         * 🚨 A hitless night with nothing driven in is not worth a name, and
         * this is where that is decided rather than where the leader is picked.
         * The leader is whoever drove in the most runs; in a 1–0 game that is
         * everybody, tied on nothing, and the first name in the order wins it.
         * "Ronald Acuna Jr. 0 for 3" was the result — a line that says a good
         * player had a quiet night, printed as though it were the highlight.
         */
        if (($hits ?? 0) === 0 && ($rbi ?? 0) === 0 && ($homeRuns ?? 0) === 0) {
            return '';
        }

        $said = $this->hitLine($line);

        if (($homeRuns ?? 0) > 0) {
            $said .= ', '.($homeRuns === 1 ? $this->t('diamond.home_run') : $this->t('diamond.home_runs', ['words' => $this->word((int) $homeRuns)]));
        }

        if ($rbi !== null && $rbi > 0) {
            $said .= ', '.$this->t('diamond.driven_in', ['n' => (string) $rbi]);
        }

        return $said;
    }

    protected function margin(string $winner, int $margin): string
    {
        return match (true) {
            $margin === 1 => $this->t('common.took_by_one', ['winner' => $winner]),
            $margin <= 3 => $this->t('common.won_by', ['winner' => $winner, 'margin' => (string) $margin]),
            $margin <= 6 => $this->t('common.comfortable', ['winner' => $winner]),
            default => $this->t('common.never_troubled', ['winner' => $winner]),
        };
    }

    protected function drawnGame(): string
    {
        /*
         * 🚨 Baseball plays extra innings until somebody wins, so a tie means a
         * suspended or called game — genuinely rare, and worth saying plainly
         * rather than dressing up as a result.
         */
        return $this->t('common.drawn_unfinished');
    }

    /** `2-4` → "2 for 4", which is how anybody says it out loud. */
    protected function hitLine(string $line): string
    {
        $parts = explode('-', $line, 2);

        return count($parts) === 2 ? $this->t('diamond.hit_line', ['hits' => $parts[0], 'atBats' => $parts[1]]) : $line;
    }
}
