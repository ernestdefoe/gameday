<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service;

use ErnestDefoe\Gameday\GamedayThread;

/**
 * A game, turned into what a scoreboard actually shows.
 *
 * 🚨 Decided HERE, not in the component. Whether a clock is still true, whether
 * a down is a fact about the game or a leftover, and what the period line should
 * say are all judgements — and a view is for choosing what to show rather than
 * for working out what a thing means. It is also what lets the first render and
 * the refresh poll answer identically: both call this.
 */
class Scoreboard
{
    /**
     * How long a game clock is still worth printing.
     *
     * 🚨 The sync floor is a minute, on purpose — ESPN's scoreboard is public
     * and unauthenticated, which is a reason to be gentle with it rather than a
     * reason to hammer it. So a clock here is at best a minute old, and "2:41 to
     * play" that is three minutes stale is simply a wrong number printed in a
     * confident font.
     *
     * The PERIOD does not have this problem: a quarter lasts fifteen minutes, so
     * it is still true long after the clock beside it stopped being. Past this
     * window the clock is dropped and the period carries the line alone.
     */
    public const CLOCK_FRESH_FOR = 180;

    /**
     * @param  object $event  a PickEvent, with its teams loaded
     * @return array<string, mixed>
     */
    public function shape(object $event, ?GamedayThread $thread = null, ?int $now = null): array
    {
        $now ??= time();

        $status = (string) ($event->status ?? '');
        $threadState = $thread->state ?? '';
        $hasScore = $event->home_score !== null && $event->away_score !== null;

        $state = match (true) {
            $status === 'finished' && $hasScore => 'final',
            $threadState === GamedayThread::LIVE || $status === 'in_progress' => 'live',
            default => 'scheduled',
        };

        $period = (int) ($event->period ?? 0);
        $clockAt = (int) ($event->clock_at ?? 0);
        $fresh = $clockAt > 0 && ($now - $clockAt) <= self::CLOCK_FRESH_FOR;
        $clock = trim((string) ($event->clock ?? ''));

        $possession = $state === 'live' && $fresh
            && in_array((string) ($event->possession ?? ''), ['home', 'away'], true)
                ? (string) $event->possession
                : null;

        /*
         * Marked on the SIDE, because that is where the ball is drawn and the
         * strip renders the two sides through one loop — a view asking "is this
         * the home one?" halfway down a loop is a view doing arithmetic.
         */
        $home = $this->side($event, 'home');
        $away = $this->side($event, 'away');
        $home['hasBall'] = $possession === 'home';
        $away['hasBall'] = $possession === 'away';

        return [
            'id' => (int) $event->id,
            'state' => $state,

            /*
             * ESPN's own wording where there is any — "2nd Quarter", "Halftime",
             * "End of 3rd". Better than anything built from a number here,
             * because it already knows what a period means in a game that has
             * gone to overtime.
             */
            'periodLine' => $this->periodLine($event, $period, $state),

            /*
             * 🚨 Not at a period boundary. Between quarters and at half the feed
             * sends a stopped "0:00", and printing it beside "Halftime" says the
             * same thing twice in a way that looks like a fault.
             */
            'clock' => $state === 'live' && $fresh && $clock !== '' && $clock !== '0:00' ? $clock : null,

            /*
             * 🚨 Possession only while it is fresh, and only during play.
             *
             * A ball marker beside a team is a strong claim — it says "they have
             * it, now". Three minutes after the fact that is simply wrong, and
             * wrong in the most visible place on the board, so it goes when the
             * clock goes rather than lingering as decoration.
             */
            'possession' => $possession,

            /*
             * 🚨 Only while somebody actually has the ball. The feed keeps
             * sending the last down through halftime, so an earlier version of
             * this printed "1st & Goal" beside "Halftime" — a down nobody was
             * playing. A down without a possession is not a fact about the game.
             */
            'down' => $possession !== null && $fresh
                ? (trim((string) ($event->down_distance ?? '')) ?: null)
                : null,

            'redZone' => $state === 'live' && $fresh && ! empty($event->red_zone),

            /*
             * Said out loud rather than left to be inferred from a missing
             * clock. "Waiting for the feed" is a different thing from "the
             * clock is stopped", and a board that silently drops its clock
             * looks broken in exactly the moment somebody is watching it.
             */
            'clockStale' => $state === 'live' && $clockAt > 0 && ! $fresh,

            'kickoff' => $event->match_date?->toIso8601String(),
            'home' => $home,
            'away' => $away,
        ];
    }

    protected function periodLine(object $event, int $period, string $state): string
    {
        if ($state === 'final') {
            // Overtime is worth saying; a regulation finish is just "Final".
            return $period > 4 ? 'Final / OT' : 'Final';
        }

        if ($state !== 'live') {
            return '';
        }

        $detail = trim((string) ($event->clock_detail ?? ''));

        /*
         * 🚨 ESPN's short detail already carries the clock — "5:44 - 2nd". The
         * clock has its own place on the strip, so printing this whole would say
         * 5:44 twice, and the second one would go stale while the first was
         * being dropped for exactly that reason.
         *
         * Everything after the dash is the part worth keeping. Where there is no
         * dash the whole thing is the period and says something a number cannot:
         * "Halftime", "End of 3rd".
         */
        if ($detail !== '') {
            $dash = strpos($detail, ' - ');

            return $dash === false ? $detail : trim(substr($detail, $dash + 3));
        }

        return $period > 0 ? self::ordinal($period) : '';
    }

    protected static function ordinal(int $period): string
    {
        return match (true) {
            $period === 1 => '1st',
            $period === 2 => '2nd',
            $period === 3 => '3rd',
            $period === 4 => '4th',
            // 5 is the first overtime, 6 the second, and so on.
            default => 'OT'.($period > 5 ? (string) ($period - 4) : ''),
        };
    }

    /** @return array<string, mixed> */
    protected function side(object $event, string $which): array
    {
        $team = $event->{$which.'Team'} ?? null;
        $score = $event->{$which.'_score'};

        return [
            'name' => (string) ($team->name ?? ''),
            'abbr' => (string) ($team->abbreviation ?? ''),
            /*
             * 🚨 The DARK-ground crest where there is one. This strip is dark in
             * both themes — it is built from chrome colours rather than the page
             * ground, which is what a real scoreboard looks like — and laying a
             * light-ground mark on it is how a navy crest becomes a navy smudge.
             *
             * 🚨 `logo_dark_url`, which this said it used and did not. Picks has
             * carried both variants all along and this read the light one, so
             * every comment above was describing an intention rather than the
             * code under it — and a dark-crested team was a dark smudge on a
             * dark panel, which reads as a missing image rather than as a bug.
             * The accessor falls back to the standard logo on its own, so a
             * team with no dark variant is unaffected.
             */
            'logo' => (string) ($team->logo_dark_url ?? $team->logo_url ?? ''),
            'score' => $score === null ? null : (int) $score,
        ];
    }
}
