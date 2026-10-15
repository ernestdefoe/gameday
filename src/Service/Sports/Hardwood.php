<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service\Sports;

/**
 * Basketball — the NBA and college.
 *
 * 🚨 The statistic names are ESPN's own, read off a live NBA summary rather
 * than guessed: `fieldGoalsMade-fieldGoalsAttempted`, `threePointFieldGoalPct`,
 * `totalRebounds`, `turnovers`, `pointsInPaint`. A key invented here would
 * simply never match, and a comparison that silently prints nothing is the
 * worst kind of wrong.
 *
 * 🚨 Basketball is the sport where the SCORE is least informative. Every game
 * ends near a hundred points and a fifteen-point win is comfortable, so the
 * margins below are nothing like gridiron's — twenty-eight points is a rout in
 * football and a normal Tuesday here is closer to twenty.
 */
class Hardwood extends Sport
{
    protected function periodAbbreviation(): string
    {
        return $this->t('hardwood.period_abbr');
    }

    protected function periodsInRegulation(): int
    {
        return 4;
    }

    public function key(): string
    {
        return 'hardwood';
    }

    public function kickoff(): string
    {
        return $this->t('hardwood.kickoff');
    }

    public function name(): string
    {
        return 'Basketball';
    }

    public function comparison(): array
    {
        return [
            'fieldGoalsMade-fieldGoalsAttempted' => $this->t('hardwood.stats.field_goals'),
            'fieldGoalPct' => $this->t('hardwood.stats.field_goal_pct'),
            'threePointFieldGoalsMade-threePointFieldGoalsAttempted' => $this->t('hardwood.stats.three_pointers'),
            'threePointFieldGoalPct' => $this->t('hardwood.stats.three_point_pct'),
            'freeThrowsMade-freeThrowsAttempted' => $this->t('hardwood.stats.free_throws'),
            'totalRebounds' => $this->t('hardwood.stats.rebounds'),
            'assists' => $this->t('hardwood.stats.assists'),
            'steals' => $this->t('hardwood.stats.steals'),
            'blocks' => $this->t('hardwood.stats.blocks'),
            'turnovers' => $this->t('hardwood.stats.turnovers'),
            'pointsInPaint' => $this->t('hardwood.stats.points_in_paint'),
        ];
    }

    public function narrative(array $sides, string $home, string $away): array
    {
        $said = [];

        /*
         * 🚨 Shooting, first and usually only. Basketball games are decided by
         * whether the ball went in far more often than by anything else on the
         * sheet, and a recap that led with rebounds would be describing the
         * consolation rather than the game.
         */
        $homePct = $this->decimal($sides['home']['stats']['fieldGoalPct'] ?? null);
        $awayPct = $this->decimal($sides['away']['stats']['fieldGoalPct'] ?? null);

        if ($homePct !== null && $awayPct !== null) {
            $gap = abs($homePct - $awayPct);

            /*
             * Six points of field-goal percentage is roughly three made shots
             * over a full game — the point at which one side genuinely shot
             * better rather than got slightly luckier.
             */
            if ($gap >= 6.0) {
                $better = $homePct > $awayPct ? $home : $away;
                $worse = $homePct > $awayPct ? $away : $home;

                $said[] = $this->t('hardwood.shot_pct', [
                    'better' => $better,
                    'betterPct' => $this->trimPct(max($homePct, $awayPct)),
                    'worse' => $worse,
                    'worsePct' => $this->trimPct(min($homePct, $awayPct)),
                ]);
            }
        }

        /*
         * 🚨 The three-point line, only when somebody actually made a
         * difference at it. Both teams take thirty of them now; the fact worth
         * saying is a gap, not a count.
         */
        $homeThrees = $this->made($sides['home']['stats']['threePointFieldGoalsMade-threePointFieldGoalsAttempted'] ?? null);
        $awayThrees = $this->made($sides['away']['stats']['threePointFieldGoalsMade-threePointFieldGoalsAttempted'] ?? null);

        if ($homeThrees !== null && $awayThrees !== null && abs($homeThrees - $awayThrees) >= 5) {
            $more = $homeThrees > $awayThrees ? $home : $away;

            $said[] = $this->t('hardwood.threes', [
                'team' => $more,
                'more' => (string) max($homeThrees, $awayThrees),
                'fewer' => (string) min($homeThrees, $awayThrees),
            ]);
        }

        // Turnovers, on the same rule as everywhere else: only a real gap.
        $homeTo = $this->number($sides['home']['stats']['turnovers'] ?? null);
        $awayTo = $this->number($sides['away']['stats']['turnovers'] ?? null);

        if ($homeTo !== null && $awayTo !== null && abs($homeTo - $awayTo) >= 6) {
            $sloppier = $homeTo > $awayTo ? $home : $away;

            $said[] = $this->t('hardwood.turnovers', [
                'team' => $sloppier,
                'times' => $this->times(max($homeTo, $awayTo)),
                'other' => $sloppier === $home ? $away : $home,
                'fewer' => (string) min($homeTo, $awayTo),
            ]);
        }

        return $said;
    }

    public function leaderCategories(): array
    {
        /*
         * 🚨 ESPN's basketball box score is ONE unnamed group of players with a
         * single row each — there is no passing/rushing/receiving split to lead
         * separately. So there is one category, and who "led" it is who scored
         * most, which is also how anybody talking about the game would pick.
         */
        return ['general' => 'PTS'];
    }

    public function playerLine(string $category, array $stats): string
    {
        $points = $this->number($stats['PTS'] ?? null);

        if ($points === null) {
            return '';
        }

        $parts = [$this->t('hardwood.points', ['n' => (string) $points])];

        $rebounds = $this->number($stats['REB'] ?? null);
        $assists = $this->number($stats['AST'] ?? null);

        /*
         * 🚨 Rebounds and assists only when they were worth mentioning. Every
         * line would otherwise read "22 points, 3 rebounds, 2 assists", which
         * is three facts where one was interesting.
         */
        if ($rebounds !== null && $rebounds >= 8) {
            $parts[] = $this->t('hardwood.rebounds', ['n' => (string) $rebounds]);
        }

        if ($assists !== null && $assists >= 6) {
            $parts[] = $this->t('hardwood.assists', ['n' => (string) $assists]);
        }

        $line = implode(', ', $parts);

        // The one thing basketball has that nothing else does.
        if (($rebounds ?? 0) >= 10 && ($assists ?? 0) >= 10 && $points >= 10) {
            $line .= ' — '.$this->t('hardwood.triple_double');
        }

        return $line;
    }

    public function formatStat(string $key, string $value): string
    {
        return str_ends_with($key, 'Pct') ? $this->percent($this->trimPct((float) $value)) : $value;
    }

    protected function margin(string $winner, int $margin): string
    {
        return match (true) {
            $margin <= 3 => $this->t('hardwood.got_out', ['winner' => $winner, 'margin' => (string) $margin]),
            $margin <= 8 => $this->t('common.took_by', ['winner' => $winner, 'margin' => (string) $margin]),
            $margin <= 15 => $this->t('common.won_by', ['winner' => $winner, 'margin' => (string) $margin]),
            $margin <= 25 => $this->t('common.comfortable', ['winner' => $winner]),
            default => $this->t('common.never_troubled', ['winner' => $winner]),
        };
    }

    protected function drawnGame(): string
    {
        /*
         * 🚨 Unreachable in practice and deliberately not deleted. Basketball
         * plays overtime until somebody wins, so a level score means the feed
         * gave us a game that was abandoned or that we read too early — and a
         * recap that said "it finished level" about a suspended game would be
         * stating something nobody claimed.
         */
        return $this->t('hardwood.drawn');
    }

    /** `13-40` → 13. The made half of a made-attempted pair. */
    protected function made(mixed $value): ?int
    {
        if (! is_string($value) || ! str_contains($value, '-')) {
            return null;
        }

        return $this->number(explode('-', $value, 2)[0]);
    }

    /** 46.0 → "46", 46.5 → "46.5". A whole percentage does not want a `.0`. */
    protected function trimPct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
