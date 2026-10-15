<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service\Sports;

/**
 * American football — college and professional alike.
 *
 * 🚨 One class for both, and that is not laziness. The vocabulary is identical
 * and so are the thresholds: twenty-eight points is a rout in either, a
 * quarterback's line reads the same, and the box score arrives in the same
 * shape. A separate NFL class would be a copy with the same numbers in it,
 * which is a copy that eventually disagrees with itself.
 */
final class Gridiron extends Sport
{
    protected function periodAbbreviation(): string
    {
        return $this->t('gridiron.period_abbr');
    }

    protected function periodsInRegulation(): int
    {
        return 4;
    }

    public function key(): string
    {
        return 'gridiron';
    }

    public function kickoff(): string
    {
        return $this->t('gridiron.kickoff');
    }

    public function name(): string
    {
        return 'American football';
    }

    /**
     * 🚨 Ordered as somebody reads a game rather than as the feed lists them:
     * how far each side moved the ball, how they moved it, and then the three
     * things that decide close games. Possession is last because it is the
     * least explanatory number on the list and the one most often mistaken for
     * one that matters.
     */
    public function comparison(): array
    {
        return [
            'firstDowns' => $this->t('gridiron.stats.first_downs'),
            'totalYards' => $this->t('gridiron.stats.total_yards'),
            'netPassingYards' => $this->t('gridiron.stats.passing_yards'),
            'rushingYards' => $this->t('gridiron.stats.rushing_yards'),
            'thirdDownEff' => $this->t('gridiron.stats.third_down'),
            'fourthDownEff' => $this->t('gridiron.stats.fourth_down'),
            'totalPenaltiesYards' => $this->t('gridiron.stats.penalties'),
            'turnovers' => $this->t('gridiron.stats.turnovers'),
            'possessionTime' => $this->t('gridiron.stats.possession'),
        ];
    }

    public function leaderCategories(): array
    {
        return [
            'passing' => 'YDS',
            'rushing' => 'YDS',
            'receiving' => 'YDS',
        ];
    }

    public function narrative(array $sides, string $home, string $away): array
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
                ? $this->t('gridiron.yardage_close', ['more' => (string) $more, 'fewer' => (string) $fewer])
                : $this->t('gridiron.outgained', ['leader' => $leader, 'trailer' => $trailer, 'more' => (string) $more, 'fewer' => (string) $fewer]);
        }

        $homeAway = $this->number($sides['home']['stats']['turnovers'] ?? null);
        $awayAway = $this->number($sides['away']['stats']['turnovers'] ?? null);

        if ($homeAway !== null && $awayAway !== null && $homeAway + $awayAway > 0) {
            $out[] = $homeAway === $awayAway
                ? $this->t('gridiron.giveaways_each', ['times' => $this->times($homeAway)])
                : $this->t('gridiron.giveaways', [
                    'team' => $homeAway > $awayAway ? $home : $away,
                    'times' => $this->times(max($homeAway, $awayAway)),
                    'other' => $homeAway > $awayAway ? $away : $home,
                    'otherTimes' => $this->times(min($homeAway, $awayAway)),
                ]);
        }

        return $out;
    }

    public function playerLine(string $category, array $stats): string
    {
        $yards = $this->number($stats['YDS'] ?? null);

        if ($yards === null) {
            return '';
        }

        $touchdowns = $this->number($stats['TD'] ?? null) ?? 0;
        $scores = match (true) {
            $touchdowns < 1 => '',
            $touchdowns === 1 => ' '.$this->t('gridiron.touchdown'),
            default => ' '.$this->t('gridiron.touchdowns', ['words' => $this->word($touchdowns)]),
        };

        return match ($category) {
            'passing' => $this->forLine(
                (string) ($stats['C/ATT'] ?? ''),
                $yards,
                $scores.$this->picks($this->number($stats['INT'] ?? null) ?? 0),
            ),
            'rushing' => $this->forLine($this->carries($this->number($stats['CAR'] ?? null)), $yards, $scores),
            'receiving' => $this->forLine($this->catches($this->number($stats['REC'] ?? null)), $yards, $scores),
            default => '',
        };
    }

    /**
     * 🚨 A draw is all but impossible here — it needs a full overtime that
     * settles nothing — so it is remarked on rather than reported flatly.
     */
    protected function drawnGame(): string
    {
        return $this->t('gridiron.drawn');
    }

    protected function margin(string $winner, int $margin): string
    {
        return match (true) {
            $margin <= 3 => $this->t('common.took_by', ['winner' => $winner, 'margin' => (string) $margin]),
            $margin >= 28 => $this->t('common.never_troubled', ['winner' => $winner]),
            $margin >= 17 => $this->t('common.comfortable', ['winner' => $winner]),
            default => $this->t('common.won_by', ['winner' => $winner, 'margin' => (string) $margin]),
        };
    }

    /**
     * 🚨 Its own wording rather than `times()`. "With once picked off" is what
     * counting words give you when they are reused for something that is not a
     * count of occasions, and it reads as a typo.
     */
    private function picks(int $n): string
    {
        return match (true) {
            $n < 1 => '',
            $n === 1 => ', '.$this->t('gridiron.interception'),
            default => ', '.$this->t('gridiron.interceptions', ['words' => $this->word($n)]),
        };
    }

    private function carries(?int $n): string
    {
        return $n === null ? $this->t('gridiron.ran') : $this->t('gridiron.carries', ['count' => $n]);
    }

    private function catches(?int $n): string
    {
        return $n === null ? $this->t('gridiron.caught') : $this->t('gridiron.catches', ['count' => $n]);
    }

    /** "22/31 for 287 and two touchdowns". */
    private function forLine(string $what, int $yards, string $rest): string
    {
        return $this->t('gridiron.for_line', ['what' => $what, 'yards' => (string) $yards, 'rest' => $rest]);
    }
}
