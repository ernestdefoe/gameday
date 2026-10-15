<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service\Sports;

/**
 * Ice hockey.
 *
 * 🚨 Statistic names read off a live NHL summary: `shotsTotal`, `powerPlayPct`,
 * `faceoffPercent`, `hits`, `blockedShots`, `giveaways`, `penaltyMinutes`.
 *
 * 🚨 Hockey's own trap is the SHOT COUNT. A team can be out-shot 40 to 20 and
 * win, and it happens often enough that a recap treating shots the way gridiron
 * treats yardage would be wrong most nights. So the shots sentence is written
 * to survive disagreeing with the score, and the goaltender is named when the
 * numbers say he is the reason.
 */
class Ice extends Sport
{
    protected function periodAbbreviation(): string
    {
        return $this->t('ice.period_abbr');
    }

    protected function periodsInRegulation(): int
    {
        return 3;
    }

    public function key(): string
    {
        return 'ice';
    }

    public function kickoff(): string
    {
        return $this->t('ice.kickoff');
    }

    public function name(): string
    {
        return 'Ice hockey';
    }

    public function comparison(): array
    {
        return [
            'shotsTotal' => $this->t('ice.stats.shots'),
            'powerPlayGoals' => $this->t('ice.stats.power_play_goals'),
            'powerPlayOpportunities' => $this->t('ice.stats.power_plays'),
            'faceoffPercent' => $this->t('ice.stats.faceoffs'),
            'blockedShots' => $this->t('ice.stats.blocked_shots'),
            'hits' => $this->t('ice.stats.hits'),
            'giveaways' => $this->t('ice.stats.giveaways'),
            'takeaways' => $this->t('ice.stats.takeaways'),
            'penaltyMinutes' => $this->t('ice.stats.penalty_minutes'),
        ];
    }

    public function narrative(array $sides, string $home, string $away): array
    {
        $said = [];

        $homeShots = $this->number($sides['home']['stats']['shotsTotal'] ?? null);
        $awayShots = $this->number($sides['away']['stats']['shotsTotal'] ?? null);

        if ($homeShots !== null && $awayShots !== null && abs($homeShots - $awayShots) >= 8) {
            $more = $homeShots > $awayShots ? $home : $away;
            $fewer = $homeShots > $awayShots ? $away : $home;

            $said[] = $this->t('ice.outshot', [
                'more' => $more,
                'fewer' => $fewer,
                'moreShots' => (string) max($homeShots, $awayShots),
                'fewerShots' => (string) min($homeShots, $awayShots),
            ]);
        }

        /*
         * 🚨 The power play, only when one was converted. "0 for 3" is the
         * ordinary night and reporting it every game buries the game where
         * somebody actually scored two of them.
         */
        $homePp = $this->number($sides['home']['stats']['powerPlayGoals'] ?? null);
        $awayPp = $this->number($sides['away']['stats']['powerPlayGoals'] ?? null);

        if (($homePp ?? 0) + ($awayPp ?? 0) > 0) {
            /*
             * 🚨 Written as a whole sentence for each case rather than as
             * clauses joined with a comma. Building it up from parts produced
             * "Dallas Stars once." on the night only the away side converted —
             * the second clause was written to lean on the first one that was
             * never added.
             */
            $said[] = match (true) {
                ($homePp ?? 0) > 0 && ($awayPp ?? 0) > 0 => $this->t('ice.power_play_both', [
                    'home' => $home,
                    'homeTimes' => $this->times((int) $homePp),
                    'away' => $away,
                    'awayTimes' => $this->times((int) $awayPp),
                ]),
                ($homePp ?? 0) > 0 => $this->t('ice.power_play', ['team' => $home, 'times' => $this->times((int) $homePp)]),
                default => $this->t('ice.power_play', ['team' => $away, 'times' => $this->times((int) $awayPp)]),
            };
        }

        return $said;
    }

    public function leaderCategories(): array
    {
        /*
         * 🚨 ESPN answers FOUR hockey groups and one of them is a decoy:
         * `forwards`, `defenses`, `goalies` and `skaters` — and `skaters`
         * carries a full set of column labels with NO athletes in it. Naming
         * from it would silently produce nothing, and naming from it as well as
         * the other two would print the same players twice.
         *
         * A skater is led by POINTS, which ESPN does not answer directly; goals
         * is the honest proxy and is what anybody would name anyway. A
         * defenceman who did nothing produces no line at all, so listing the
         * group costs a name only on the night one earned it.
         */
        return [
            'forwards' => 'G',
            'defenses' => 'G',
            'goalies' => 'SV',
        ];
    }

    public function playerLine(string $category, array $stats): string
    {
        if ($category === 'goalies') {
            $saves = $this->number($stats['SV'] ?? null);
            $against = $this->number($stats['GA'] ?? null);

            if ($saves === null) {
                return '';
            }

            /*
             * A shutout is the one goaltending line worth calling by its name.
             */
            if ($against === 0) {
                return $this->t('ice.saves_shutout', ['n' => (string) $saves]);
            }

            return $this->t('ice.saves', ['n' => (string) $saves]);
        }

        $goals = $this->number($stats['G'] ?? null);
        $assists = $this->number($stats['A'] ?? null);

        if ($goals === null && $assists === null) {
            return '';
        }

        $parts = [];

        if (($goals ?? 0) > 0) {
            $parts[] = $goals === 1 ? $this->t('ice.goal') : $this->t('ice.goals', ['words' => $this->word((int) $goals)]);
        }

        if (($assists ?? 0) > 0) {
            $parts[] = $assists === 1 ? $this->t('ice.assist') : $this->t('ice.assists', ['words' => $this->word((int) $assists)]);
        }

        if ($parts === []) {
            return '';
        }

        $line = count($parts) === 2 ? $this->t('ice.goals_and_assists', ['goals' => $parts[0], 'assists' => $parts[1]]) : $parts[0];

        // Three goals is a hat-trick and the whole rink knows it.
        if (($goals ?? 0) >= 3) {
            $line .= ' — '.$this->t('ice.hat_trick');
        }

        return $line;
    }

    public function formatStat(string $key, string $value): string
    {
        return in_array($key, ['powerPlayPct', 'faceoffPercent'], true) ? $this->percent($value) : $value;
    }

    protected function margin(string $winner, int $margin): string
    {
        return match (true) {
            $margin === 1 => $this->t('common.took_by_one', ['winner' => $winner]),
            $margin === 2 => $this->t('common.won_by_two', ['winner' => $winner]),
            $margin <= 4 => $this->t('common.comfortable', ['winner' => $winner]),
            default => $this->t('common.never_troubled', ['winner' => $winner]),
        };
    }

    protected function drawnGame(): string
    {
        /*
         * 🚨 The NHL has not had a tie since 2005 — overtime and a shootout
         * settle every game. A level score means the feed handed us a game that
         * was not finished.
         */
        return $this->t('common.drawn_unfinished');
    }
}
