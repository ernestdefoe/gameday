<?php

namespace ErnestDefoe\Gameday\Console;

use ErnestDefoe\Gameday\Service\Settings;
use ErnestDefoe\Gameday\Service\Threads;
use Illuminate\Console\Command;

/**
 * One pass over every game thread that needs something doing to it.
 *
 * 🚨 Resolve, then start, then open — the reverse of the order they happen in.
 * A game that finished while the site was down should be closed out in the same
 * tick that opens the next one, and doing it in this order means a single pass
 * can carry a thread all the way through rather than taking three ticks over
 * it.
 */
class TickCommand extends Command
{
    protected $signature = 'gameday:tick';

    protected $description = 'Open, start and resolve game threads.';

    public function handle(Threads $threads, Settings $settings): int
    {
        if (!$settings->enabled()) {
            return self::SUCCESS;
        }

        $resolved = $threads->resolve();
        $started = $threads->start();
        $opened = $threads->open();

        // Silent when there was nothing to do: a minutely cron entry should not
        // fill a mailbox with output about having done nothing.
        if ($resolved + $started + $opened > 0) {
            $this->info("Opened {$opened}, started {$started}, resolved {$resolved}.");
        }

        return self::SUCCESS;
    }
}
