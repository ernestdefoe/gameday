<?php

namespace ErnestDefoe\Gameday\Tests\integration\console;

use Carbon\Carbon;
use ErnestDefoe\Gameday\GamedayThread;
use ErnestDefoe\Gameday\Service\Threads;
use ErnestDefoe\Gameday\Service\Words;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/*
 * A thread opened days ahead keeps the ranks the poll has NOW, until kickoff.
 * Team 2 was #18 when every thread below opened and has since dropped out;
 * Team 1 has moved up to #5.
 */
class RankRefreshTest extends TestCase
{
    /** Still carries Game Day's closing line, so only edited_at tells it from a person's post. */
    private const STALE = '<t><p>#18 Team 2 visits. Thread is open — predictions, complaints and everything in between.</p></t>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks', 'ernestdefoe-gameday');
        $this->setting('ernestdefoe-gameday.author_id', 1);

        // The titles are asserted word for word, so they come out of the real
        // locale file rather than the bare keys an untranslated test boots with.
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', __DIR__.'/../../../resources/locale/en.yml', 'en', 'messages+intl-icu');
        Words::use(fn (string $id, array $params): string => $translator->trans($id, $params));
    }

    protected function tearDown(): void
    {
        Words::use(null);

        parent::tearDown();
    }

    /**
     * @param array<int, array{title: string, kickoff: Carbon, edited?: bool}> $threads
     */
    private function threads(array $threads): void
    {
        $events = $discussions = $posts = $rows = [];

        foreach ($threads as $id => $thread) {
            $events[] = ['id' => $id, 'week_id' => 1, 'home_team_id' => 1, 'away_team_id' => 2, 'home_rank' => 5, 'away_rank' => 0, 'match_date' => $thread['kickoff'], 'cutoff_date' => $thread['kickoff'], 'status' => 'scheduled'];
            $discussions[] = ['id' => $id, 'title' => $thread['title'], 'slug' => "game-$id", 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => $id, 'comment_count' => 1];
            $posts[] = ['id' => $id, 'discussion_id' => $id, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => self::STALE, 'edited_at' => ($thread['edited'] ?? false) ? Carbon::now() : null];
            $rows[] = ['event_id' => $id, 'discussion_id' => $id, 'state' => GamedayThread::OPEN];
        }

        $this->prepareDatabase([
            'picks_teams' => array_map(fn ($id) => ['id' => $id, 'name' => "Team $id", 'slug' => "team-$id", 'abbreviation' => "T$id", 'conference' => 'Conf'], [1, 2]),
            'picks_seasons' => [['id' => 1, 'name' => '2026', 'slug' => '2026', 'year' => 2026]],
            'picks_weeks' => [['id' => 1, 'season_id' => 1, 'name' => 'Week 11', 'week_number' => 11]],
            'picks_events' => $events,
            Discussion::class => $discussions,
            Post::class => $posts,
            'gameday_threads' => $rows,
        ]);
    }

    /** Exactly what the hourly gameday:enrich asks for. */
    private function refresh(): array
    {
        return $this->app()->getContainer()->make(Threads::class)->rewriteOpeners(
            limit: 100,
            withTitles: true,
            includeCurrent: true,
            state: GamedayThread::OPEN,
            upcomingOnly: true
        );
    }

    private function title(int $id): string
    {
        return $this->database()->table('discussions')->where('id', $id)->value('title');
    }

    private function content(int $id): string
    {
        return $this->database()->table('posts')->where('id', $id)->value('content');
    }

    #[Test]
    public function a_rank_change_reaches_the_title_and_the_opener()
    {
        $this->threads([1 => ['title' => '#18 Team 2 at Team 1', 'kickoff' => Carbon::now()->addDays(2)]]);

        $result = $this->refresh();

        $this->assertSame(1, $result['retitled']);
        $this->assertSame('Team 2 at #5 Team 1', $this->title(1), 'Team 2 dropped out, so it loses its #18');
        $this->assertNotSame(self::STALE, $this->content(1));

        $this->assertSame(0, $this->refresh()['rewritten'], 'A second pass has nothing left to change');
    }

    #[Test]
    public function a_title_a_moderator_renamed_is_left_alone()
    {
        $this->threads([1 => ['title' => 'Rivalry week: Team 2 at Team 1', 'kickoff' => Carbon::now()->addDays(2)]]);

        $this->refresh();

        $this->assertSame('Rivalry week: Team 2 at Team 1', $this->title(1));
        $this->assertNotSame(self::STALE, $this->content(1), 'The opener is still ours to keep current');
    }

    #[Test]
    public function an_edited_opener_is_left_alone_but_the_title_still_moves()
    {
        $this->threads([1 => ['title' => '#18 Team 2 at Team 1', 'kickoff' => Carbon::now()->addDays(2), 'edited' => true]]);

        $this->refresh();

        $this->assertSame(self::STALE, $this->content(1), 'A person edited it, so it is theirs');
        $this->assertSame('Team 2 at #5 Team 1', $this->title(1));
    }

    #[Test]
    public function a_game_that_has_kicked_off_keeps_the_ranks_it_was_played_under()
    {
        $this->threads([1 => ['title' => '#18 Team 2 at Team 1', 'kickoff' => Carbon::now()->subMinutes(5)]]);

        $this->assertSame(0, $this->refresh()['rewritten']);
        $this->assertSame('#18 Team 2 at Team 1', $this->title(1));
        $this->assertSame(self::STALE, $this->content(1));
    }

    #[Test]
    public function the_pass_costs_the_same_queries_for_one_thread_or_twenty()
    {
        $this->threads(array_fill_keys(range(1, 20), ['title' => 'Team 2 at #5 Team 1', 'kickoff' => Carbon::now()->addDays(2)]));
        $this->refresh();

        $db = $this->app()->getContainer()->make('db')->connection();
        $count = function (array $ids) use ($db) {
            $db->table('gameday_threads')->whereNotIn('event_id', $ids)->update(['state' => GamedayThread::RESOLVED]);
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->refresh();
            $db->disableQueryLog();

            return count($db->getQueryLog());
        };

        $twenty = $count(range(1, 20));
        $one = $count([1]);

        $this->assertSame($one, $twenty, 'Nothing changed, and nothing is looked up per thread');
    }
}
