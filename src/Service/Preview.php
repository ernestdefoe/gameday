<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Service;

use ErnestDefoe\Gameday\Service\Sports\Gridiron;
use ErnestDefoe\Gameday\Service\Sports\Sport;

/**
 * The post a game thread opens with.
 *
 * 🚨 The SIBLING of `Recap`, and built on exactly the same terms: a pure
 * function of what is known about a game, returning post text, with the markup
 * asked for rather than assumed. What a recap is to the final whistle this is
 * to the three hours before kickoff.
 *
 * 🚨 It exists because the opening post used to be one sentence — "Alabama at
 * Kentucky kicks off 2 hours from now." — and a sentence like that has two
 * problems. It says nothing somebody could not read in the title, so there is
 * no reason to open the thread; and it is written in relative time, so by
 * Sunday the first post on a finished game claims it starts in two hours. A
 * post is permanent. It should say things that stay true.
 *
 * 🚨 EVERY LINE IS EARNED, and that is the rule the whole class is arranged
 * around. Rank, records, venue, channel and conference all arrive from the feed
 * for some games and not others — so this is written to leave a line out rather
 * than to print a heading with a dash after it. A preview that always has five
 * lines has five lines of nothing on a fixture the feed barely covered, which
 * is worse than the one sentence it replaced.
 */
class Preview
{
    public function __construct(
        protected string $emphasis = Recap::EMPHASIS_NONE,
        protected Sport $sport = new Gridiron(),
        /**
         * 🚨 The zone the TIME IS PRINTED IN, and the reason the abbreviation
         * is always printed beside it.
         *
         * Flarum keeps every date in UTC and has no forum-wide timezone to
         * consult, so this has to be told which clock the board keeps. The
         * default is EASTERN rather than UTC because UTC is true and useless —
         * "kickoff is 11:15pm UTC" is a number every reader has to convert,
         * which is barely an improvement on the relative time this replaced.
         *
         * What must never happen is "7:30pm" with no zone on it: that is a
         * wrong number printed in a confident font to everybody who does not
         * happen to live beside the server.
         */
        protected string $timezone = 'America/New_York',
    ) {
    }

    /**
     * @param array{
     *     home_name: string, away_name: string,
     *     home_rank?: int, away_rank?: int,
     *     home_record?: string, away_record?: string,
     *     home_conference?: string, away_conference?: string,
     *     neutral_site?: bool,
     *     kickoff?: \DateTimeInterface|null,
     *     venue?: string, venue_city?: string, broadcast?: string,
     *     week?: string
     * } $game
     */
    public function text(array $game): string
    {
        $home = (string) ($game['home_name'] ?? '');
        $away = (string) ($game['away_name'] ?? '');

        $blocks = [$this->headline($game, $home, $away)];

        foreach ([$this->when($game), $this->form($game, $home, $away), $this->stakes($game, $home, $away)] as $block) {
            if ($block !== '') {
                $blocks[] = $block;
            }
        }

        /*
         * 🚨 Last, and always — the counterpart of the recap's closing line.
         * The opening post of an automated thread has one job beyond saying
         * what the game is, which is to make clear that a person is expected to
         * reply to it. Without that, a board of machine-written threads reads
         * like a noticeboard rather than a conversation.
         */
        $blocks[] = 'Thread is open — predictions, complaints and everything in between.';

        return implode("\n\n", $blocks);
    }

    /* ------------------------------------------------------------- the lines */

    /**
     * "#12 Alabama at Kentucky — Week 2".
     *
     * 🚨 The ranks are repeated here even though the title carries them. A
     * reader arriving from a notification, a quote or a search result has the
     * post and not the title, and this is the line that says what the game is.
     *
     * @param array<string, mixed> $game
     */
    protected function headline(array $game, string $home, string $away): string
    {
        $joiner = ! empty($game['neutral_site']) ? ' vs ' : ' at ';

        $line = $this->ranked($away, (int) ($game['away_rank'] ?? 0))
            . $joiner
            . $this->ranked($home, (int) ($game['home_rank'] ?? 0));

        $week = trim((string) ($game['week'] ?? ''));

        return $this->bold($line) . ($week === '' ? '' : ' — ' . $week);
    }

    /**
     * When it starts, where it is played and who is showing it.
     *
     * 🚨 One paragraph rather than three labelled lines. These are the things
     * somebody says in one breath — "half seven at Kroger Field, it is on ABC"
     * — and a preview set out as a table of fields reads like a form even when
     * it is full, which it usually is not.
     *
     * @param array<string, mixed> $game
     */
    protected function when(array $game): string
    {
        $kickoff = $game['kickoff'] ?? null;

        $sentences = [];

        if ($kickoff instanceof \DateTimeInterface) {
            $at = (new \DateTimeImmutable('@' . $kickoff->getTimestamp()))
                ->setTimezone(new \DateTimeZone($this->timezone));

            $where = trim((string) ($game['venue'] ?? ''));
            $city = trim((string) ($game['venue_city'] ?? ''));

            $sentences[] = sprintf(
                '%s is %s%s.',
                $this->sport->kickoff(),
                /*
                 * Time first, then the day: "3:30pm EDT on Saturday 12
                 * September". The other way round it runs into the venue that
                 * follows it and the sentence reads as a list of fields.
                 *
                 * 🚨 The zone is ALWAYS said. The alternative is a number that
                 * is wrong for most of the people reading it, in a post that is
                 * never rewritten.
                 */
                $at->format('g:ia T') . ' on ' . $at->format('l j F'),
                $where === '' ? '' : ', at ' . $where . ($city === '' ? '' : ', ' . $city),
            );
        }

        $broadcast = trim((string) ($game['broadcast'] ?? ''));

        if ($broadcast !== '') {
            $sentences[] = 'On ' . $broadcast . '.';
        }

        return implode(' ', $sentences);
    }

    /**
     * What each side has done so far.
     *
     * 🚨 Only where BOTH records are known. "Alabama come in 2-0" with nothing
     * said about who they are playing is half a sentence, and the half that is
     * missing is the one that made it worth writing.
     *
     * @param array<string, mixed> $game
     */
    protected function form(array $game, string $home, string $away): string
    {
        $homeRecord = trim((string) ($game['home_record'] ?? ''));
        $awayRecord = trim((string) ($game['away_record'] ?? ''));

        if ($homeRecord === '' || $awayRecord === '') {
            return '';
        }

        /*
         * 🚨 Two unbeaten sides is a fact about the FIXTURE, not two facts about
         * two teams, and it is the most interesting thing a record can say in
         * September. Said once, rather than leaving a reader to notice that
         * neither number has a loss after it.
         */
        if ($this->unbeaten($homeRecord) && $this->unbeaten($awayRecord)) {
            return sprintf(
                'Both come in unbeaten — %s %s, %s %s.',
                $away,
                $awayRecord,
                $home,
                $homeRecord,
            );
        }

        return sprintf('%s are %s, %s %s.', $away, $awayRecord, $home, $homeRecord);
    }

    /**
     * Why this one is worth an afternoon.
     *
     * @param array<string, mixed> $game
     */
    protected function stakes(array $game, string $home, string $away): string
    {
        $said = [];

        /*
         * 🚨 Only when BOTH sides are ranked, and the rule is the earned-line
         * one again. "Alabama are ranked; Kentucky are not" is a sentence that
         * re-reads the headline three lines above it — the "#12" is right there
         * — and a preview whose second paragraph restates its first is how an
         * automated post starts sounding automated.
         *
         * Two ranked sides is different: it is a fact about the FIXTURE, the
         * thing that makes it the one to watch this week, and nothing else on
         * the page says it out loud.
         */
        if ((int) ($game['home_rank'] ?? 0) > 0 && (int) ($game['away_rank'] ?? 0) > 0) {
            $said[] = 'Two ranked sides.';
        }

        /*
         * 🚨 Read off the two teams rather than stored. Picks already knows what
         * conference each club is in, so a conference game is two strings being
         * equal — and a column carrying the same fact would be a column that can
         * disagree with them.
         */
        $conference = trim((string) ($game['home_conference'] ?? ''));

        if ($conference !== '' && $conference === trim((string) ($game['away_conference'] ?? ''))) {
            $said[] = 'It is ' . $this->article($conference) . ' ' . $conference . ' game.';
        }

        return implode(' ', $said);
    }

    /**
     * "a" or "an", for a conference name nobody wrote this code knowing.
     *
     * 🚨 Decided on how the name is SAID, not on how it is spelled, which is
     * the only rule that works here. "SEC" begins with a consonant and is read
     * "ess-ee-see", so it takes "an"; "Big Ten" begins with a consonant and
     * takes "a". A vowel-letter test gets both of those wrong, and "a SEC game"
     * on an American football board is the kind of small wrongness that makes a
     * post read as machine-written — which is precisely what this file exists
     * to avoid.
     */
    protected function article(string $name): string
    {
        $first = strtoupper(substr($name, 0, 1));

        /*
         * No lowercase in it at all — "SEC", "ACC", "C-USA" — means it is read
         * out letter by letter, so the sound that matters is the NAME of the
         * first letter. Those whose names open on a vowel are ay, eff, ell, em,
         * en, ar, ess, ex, plus the vowels themselves.
         *
         * 🚨 An acronym said as a WORD would defeat this — "a MAC game", not
         * "an MAC game" — and nothing in a string can tell the two apart. The
         * feed writes those conferences out in full ("Mid-American"), so this
         * has no case to get wrong here; it is worth knowing before anybody
         * reuses it somewhere that does.
         */
        if (preg_match('/[a-z]/', $name) !== 1) {
            return str_contains('AEFHILMNORSX', $first) ? 'an' : 'a';
        }

        return str_contains('AEIOU', $first) ? 'an' : 'a';
    }

    /* ------------------------------------------------------------- plumbing */

    /**
     * "No. 12 Alabama", or just "Alabama".
     *
     * 🚨 "No. 12", NOT "#12", and this is a correctness fix rather than a
     * style preference.
     *
     * A post is run through the board's formatter, and `#12` is a token there.
     * On the first board this shipped to, the cross-references extension turned
     * every rank into a link to the discussion with that id — "Louisiana Tech
     * at #8 LSU" rendered as "Louisiana Tech at Down goes #5 Ole Miss LSU",
     * with the wrong thread's title sitting inside the team's name. Flarum's
     * own Mentions does the same thing with `#id`. Eighty-six posts, all
     * plausible-looking until you read one.
     *
     * 🚨 A backslash escape would depend on which formatter is installed, which
     * is the same mistake as assuming Markdown — see `bold()`. "No. 12" is
     * inert whatever is parsing it, and it is what AP style writes in prose
     * anyway. The scoreboard keeps "#12": that is HTML this extension renders
     * itself, nothing parses it, and a scoreboard is where the short form
     * belongs.
     */
    protected function ranked(string $name, int $rank): string
    {
        return $rank > 0 ? 'No. ' . $rank . ' ' . $name : $name;
    }

    /**
     * Whether a record has no losses in it.
     *
     * 🚨 Reads the SECOND figure, and treats anything it cannot parse as a loss
     * rather than as a clean sheet. "2-0" is unbeaten, "2-0-1" is still
     * unbeaten in a sport with draws, and an empty or malformed record must not
     * quietly become the strongest claim this post makes.
     */
    protected function unbeaten(string $record): bool
    {
        if (! preg_match('/^(\d+)\s*-\s*(\d+)/', $record, $matches)) {
            return false;
        }

        // A side that has not played is not unbeaten, it is unstarted.
        return (int) $matches[2] === 0 && (int) $matches[1] > 0;
    }

    protected function bold(string $text): string
    {
        return match ($this->emphasis) {
            Recap::EMPHASIS_BBCODE => '[b]' . $text . '[/b]',
            Recap::EMPHASIS_MARKDOWN => '**' . $text . '**',
            default => $text,
        };
    }
}
