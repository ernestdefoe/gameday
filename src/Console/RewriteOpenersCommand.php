<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Console;

use ErnestDefoe\Gameday\GamedayThread;
use ErnestDefoe\Gameday\Service\Threads;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Rewrites opening posts that were written before there was anything to say.
 *
 * 🚨 The counterpart of `enrich()`, and it works on the same principle: the
 * opening post goes up hours before kickoff, the facts that make it worth
 * reading arrive later, and a second post would be noise — so the first one is
 * filled out in place. The difference is only which "later" this is. `enrich()`
 * waits hours for a box score; this reaches back over every thread opened
 * before the fixture carried a rank at all.
 *
 * 🚨 It will not touch a post it did not write, and that guard is the whole
 * reason this is safe to run against a live board. Three conditions, all of
 * which must hold: the post is the discussion's first, its author is the
 * account Game Day posts as, and its text is still one of the shapes this
 * extension has generated. Anything a person has edited, replied under or
 * rewritten is left exactly as it is.
 */
class RewriteOpenersCommand extends AbstractCommand
{
    /**
     * Text only this extension writes.
     *
     * 🚨 Matched on the CLOSING line rather than on "kicks off". A member
     * opening their own thread may well write "kicks off in an hour"; nobody
     * writes this sentence. It is also the line every generated opener has had
     * since the first version, which is what makes it a reliable signature
     * rather than a guess about wording.
     */
    private const OLD_SIGNATURE = 'This thread opens before the game and stays here afterwards.';

    /** The sign-off the current preview ends with, so a rerun is a no-op. */
    private const NEW_SIGNATURE = 'Thread is open — predictions, complaints and everything in between.';

    public function __construct(protected Threads $threads)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('gameday:rewrite-openers')
            ->setDescription('Rewrite game thread opening posts that pre-date the preview.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many to rewrite in one run.', '200')
            ->addOption('titles', null, InputOption::VALUE_NONE, 'Also put the rank into the thread title, where the title is still the one Game Day generated.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include openers already in the current shape, to pick up a rank that arrived since.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the first few and write nothing.');
    }

    protected function fire(): int
    {
        $limit = max(1, (int) $this->input->getOption('limit'));
        $dry = (bool) $this->input->getOption('dry-run');
        $titles = (bool) $this->input->getOption('titles');
        $all = (bool) $this->input->getOption('all');

        $result = $this->threads->rewriteOpeners(
            limit: $limit,
            withTitles: $titles,
            includeCurrent: $all,
            dryRun: $dry,
            // Shown rather than counted, for the first few — a command that
            // rewrites posts on a live board should be easy to check before it
            // is trusted, and `--dry-run` is only useful if it prints something.
            report: function (string $title, string $text) use ($dry): void {
                static $shown = 0;

                if (! $dry || $shown >= 3) {
                    return;
                }

                $shown++;
                $this->output->writeln('');
                $this->output->writeln('<info>' . $title . '</info>');
                $this->output->writeln($text);
            }
        );

        $this->output->writeln('');
        $this->info(sprintf(
            '%s %d opening post(s). %d left alone (edited, already current, or the fixture is gone)%s.',
            $dry ? 'Would rewrite' : 'Rewrote',
            $result['rewritten'],
            $result['skipped'],
            $titles ? sprintf(', %d title(s) %s', $result['retitled'], $dry ? 'would change' : 'changed') : ''
        ));

        return 0;
    }
}
