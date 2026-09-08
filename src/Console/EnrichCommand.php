<?php

namespace ErnestDefoe\Gameday\Console;

use ErnestDefoe\Gameday\Service\Settings;
use ErnestDefoe\Gameday\Service\Threads;
use Illuminate\Console\Command;

/**
 * Fills out recaps that were posted before the box score arrived.
 *
 * 🚨 Hourly, and separate from the tick on purpose. A recap is posted the
 * moment a game settles and the statistics do not exist yet — the provider
 * publishes them minutes to hours later. This is a different cadence to
 * everything the tick does, and running it minutely would be sixty passes an
 * hour to do nothing.
 */
class EnrichCommand extends Command
{
    protected $signature = 'gameday:enrich';

    protected $description = 'Rewrite recaps whose box score has since arrived.';

    public function handle(Threads $threads, Settings $settings): int
    {
        if (!$settings->enabled()) {
            return self::SUCCESS;
        }

        $rewritten = $threads->enrich();

        if ($rewritten > 0) {
            $this->info("Filled out {$rewritten} recap(s).");
        }

        return self::SUCCESS;
    }
}
