<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service;

use Carbon\Carbon;
use ErnestDefoe\Gameday\GamedayThread;

/**
 * Asks Picks for the highlight clips of games that have a thread.
 *
 * 🚨 Only those. Picks could fetch clips for every finished fixture, and on a
 * college football Saturday that is sixty summaries for clips nobody will see —
 * the clips are drawn in a game thread, so a game thread is what earns the
 * request.
 *
 * 🚨 The POST is never touched. The recap is generated text that Flarum parses
 * (a "#12" in one became a link), and an iframe written into it would be
 * escaped into noise or, on a board with HTML allowed, trusted — neither is
 * acceptable for markup built from a feed. The clips live on the fixture and
 * the thread draws them in its own component, under the scoreboard. So a new
 * clip is a changed row, not a rewritten post, and a pass that finds nothing
 * new writes nothing but its check time.
 *
 * 🚨 Hourly, with the recap pass, and every limit lives in Picks'
 * GameHighlights: at most every 45 minutes per game, never past six clips, never
 * more than 30 hours after kickoff. BATCH caps one run on top of that.
 */
class Highlights
{
    public const BATCH = 12;

    private const SERVICE = '\\Resofire\\Picks\\Service\\GameHighlights';

    private const EVENT = '\\Resofire\\Picks\\PickEvent';

    public function __construct(protected Settings $settings)
    {
    }

    /** @return array{checked: int, updated: int} */
    public function collect(): array
    {
        $out = ['checked' => 0, 'updated' => 0];

        if (! $this->settings->highlightsEnabled() || ! class_exists(self::SERVICE) || ! class_exists(self::EVENT)) {
            return $out;
        }

        $service = resolve(self::SERVICE);
        $model = self::EVENT;

        $ids = GamedayThread::query()
            ->where('state', GamedayThread::RESOLVED)
            ->where('resolved_at', '>', Carbon::now()->subHours(36))
            ->pluck('event_id');

        if ($ids->isEmpty()) {
            return $out;
        }

        $events = $model::query()->with('week.season')->whereIn('id', $ids)->orderByDesc('match_date')->get();

        foreach ($events as $event) {
            if ($out['checked'] >= self::BATCH) {
                break;
            }

            if (! $service->due($event)) {
                continue;
            }

            $out['checked']++;

            try {
                if ($service->refresh($event)) {
                    $out['updated']++;
                }
            } catch (\Throwable $e) {
                // One game's summary failing is one thread without clips.
                // The next pass asks again; the recap pass beside this must
                // not die with it.
            }
        }

        return $out;
    }
}
