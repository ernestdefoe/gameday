<?php

namespace ErnestDefoe\Gameday\Tests\integration\console;

use Carbon\Carbon;
use ErnestDefoe\Gameday\GamedayThread;
use ErnestDefoe\Gameday\Service\Threads;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RewriteOpenersTest extends TestCase
{
    /** Still carries Game Day's closing line, so only edited_at tells the two apart. */
    private const STALE = '<t><p>Kickoff moved. Thread is open — predictions, complaints and everything in between.</p></t>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks', 'ernestdefoe-gameday');
        $this->setting('ernestdefoe-gameday.author_id', 1);

        $kickoff = Carbon::now()->addDay();
        $events = $discussions = $posts = $threads = [];

        // Game 1's opener is untouched; game 2's was edited by a moderator.
        foreach ([1, 2] as $id) {
            $events[] = ['id' => $id, 'week_id' => 1, 'home_team_id' => $id * 2 - 1, 'away_team_id' => $id * 2, 'match_date' => $kickoff, 'cutoff_date' => $kickoff, 'status' => 'scheduled'];
            $discussions[] = ['id' => $id, 'title' => "Game $id", 'slug' => "game-$id", 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => $id, 'comment_count' => 1];
            $posts[] = ['id' => $id, 'discussion_id' => $id, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => self::STALE, 'edited_at' => $id === 2 ? Carbon::now() : null, 'edited_user_id' => $id === 2 ? 1 : null];
            $threads[] = ['event_id' => $id, 'discussion_id' => $id, 'state' => GamedayThread::OPEN];
        }

        $this->prepareDatabase([
            'picks_teams' => array_map(fn ($id) => ['id' => $id, 'name' => "Team $id", 'slug' => "team-$id", 'abbreviation' => "T$id", 'conference' => 'Conf'], range(1, 4)),
            'picks_seasons' => [['id' => 1, 'name' => '2026', 'slug' => '2026', 'year' => 2026]],
            'picks_weeks' => [['id' => 1, 'season_id' => 1, 'name' => 'Week 6', 'week_number' => 6]],
            'picks_events' => $events,
            Discussion::class => $discussions,
            Post::class => $posts,
            'gameday_threads' => $threads,
        ]);
    }

    #[Test]
    public function an_unedited_opener_is_kept_up_to_date_and_an_edited_one_is_left_alone()
    {
        $this->app();

        // Exactly what the hourly gameday:enrich asks for.
        $result = $this->app()->getContainer()->make(Threads::class)
            ->rewriteOpeners(limit: 100, includeCurrent: true, state: GamedayThread::OPEN);

        $this->assertSame(1, $result['rewritten']);

        $content = fn (int $id) => $this->database()->table('posts')->where('id', $id)->value('content');

        $this->assertNotSame(self::STALE, $content(1), 'The untouched opener is refreshed');
        $this->assertSame(self::STALE, $content(2), 'A person edited this one, so it is theirs');
    }
}
