<?php

namespace ErnestDefoe\Gameday\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Gameday\GamedayThread;
use ErnestDefoe\Gameday\TeamTag;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

class GamedayTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks', 'ernestdefoe-gameday');

        $teams = [];
        for ($id = 1; $id <= 20; $id++) {
            $teams[] = ['id' => $id, 'name' => "Team $id", 'slug' => "team-$id", 'abbreviation' => "T$id", 'conference' => 'Conf'];
        }

        $kickoff = Carbon::now()->addDay();
        $events = [];
        $discussions = [];
        $posts = [];
        $threads = [];

        // Eight upcoming games, each with its thread. Game 8's thread is in a
        // private discussion nobody here can read.
        for ($id = 1; $id <= 8; $id++) {
            $events[] = ['id' => $id, 'week_id' => 1, 'home_team_id' => $id * 2 - 1, 'away_team_id' => $id * 2, 'match_date' => $kickoff->copy()->addMinutes($id), 'cutoff_date' => $kickoff, 'status' => 'scheduled'];
            $discussions[] = ['id' => $id, 'title' => "Game $id", 'slug' => "game-$id", 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => $id, 'comment_count' => 1, 'is_private' => $id === 8 ? 1 : 0];
            $posts[] = ['id' => $id, 'discussion_id' => $id, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Kickoff</p></t>', 'is_private' => $id === 8 ? 1 : 0];
            $threads[] = ['event_id' => $id, 'discussion_id' => $id, 'state' => GamedayThread::OPEN];
        }

        // A discussion that is not a game thread.
        $discussions[] = ['id' => 20, 'title' => 'Chat', 'slug' => 'chat', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 20, 'comment_count' => 1];
        $posts[] = ['id' => 20, 'discussion_id' => 20, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Hi</p></t>'];

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            'picks_teams' => $teams,
            'picks_seasons' => [['id' => 1, 'name' => '2026', 'slug' => '2026', 'year' => 2026]],
            'picks_weeks' => [['id' => 1, 'season_id' => 1, 'name' => 'Week 6', 'week_number' => 6]],
            'picks_events' => $events,
            Discussion::class => $discussions,
            Post::class => $posts,
            'gameday_threads' => $threads,
        ]);
    }

    private function get(string $path, ?int $actor = null): ResponseInterface
    {
        return $this->send($this->request('GET', $path, $actor ? ['authenticatedAs' => $actor] : []));
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function react(?int $actor, int $discussion, string $emoji): ResponseInterface
    {
        $request = $this->request('POST', "/api/gameday/reactions/$discussion", array_filter([
            'authenticatedAs' => $actor,
            'json' => ['emoji' => $emoji],
        ]));

        // A guest has no session to carry a CSRF token; skip that check so the
        // controller's own answer is what is tested.
        return $this->send($request->withAttribute('bypassCsrfToken', true));
    }

    #[Test]
    public function a_game_threads_board_is_served_and_a_private_one_is_not()
    {
        $response = $this->get('/api/gameday/board/1');
        $this->assertSame(200, $response->getStatusCode());
        $board = $this->json($response)['board'];
        $this->assertSame(1, $board['id']);
        $this->assertSame('scheduled', $board['state']);

        $this->assertSame(404, $this->get('/api/gameday/board/8')->getStatusCode());
        $this->assertNull($this->json($this->get('/api/gameday/board/20'))['board'], 'Not a game thread');
    }

    #[Test]
    public function the_discussion_carries_its_board_and_only_when_shown()
    {
        $one = $this->json($this->get('/api/discussions/1'))['data']['attributes'];
        $this->assertSame(1, $one['gamedayBoard']['id'] ?? null);

        $chat = $this->json($this->get('/api/discussions/20'))['data']['attributes'];
        $this->assertArrayHasKey('gamedayBoard', $chat);
        $this->assertNull($chat['gamedayBoard']);

        // The list never carries it: one board per row would be a query per row.
        $list = $this->json($this->get('/api/discussions'))['data'];
        $this->assertCount(8, $list);
        foreach ($list as $discussion) {
            $this->assertArrayNotHasKey('gamedayBoard', $discussion['attributes']);
        }
    }

    #[Test]
    public function the_strip_shows_every_score_but_links_only_threads_the_reader_can_see()
    {
        $response = $this->get('/api/gameday/board');
        $this->assertSame(200, $response->getStatusCode());
        $boards = $this->json($response)['boards'];

        $byId = array_column($boards, null, 'id');
        $this->assertCount(8, $byId, 'One query for the whole strip, whatever its length');
        $this->assertSame(1, $byId[1]['discussion']['id']);
        $this->assertArrayHasKey(8, $byId, 'The score is public');
        $this->assertNull($byId[8]['discussion'], 'The thread is not');
    }

    #[Test]
    public function only_a_member_reacts_and_only_while_the_game_is_live()
    {
        $this->assertSame(401, $this->react(null, 1, '🔥')->getStatusCode());
        $this->assertSame(409, $this->react(2, 1, '🔥')->getStatusCode(), 'Not live yet');
        $this->assertSame(404, $this->react(2, 8, '🔥')->getStatusCode(), 'A thread the member cannot see');

        $this->database()->table('gameday_threads')->where('discussion_id', 1)->update(['state' => GamedayThread::LIVE]);

        $this->assertTrue($this->json($this->react(2, 1, '🔥'))['accepted']);
        $this->assertFalse($this->json($this->react(2, 1, 'not an emoji'))['accepted']);

        $reactions = $this->json($this->get('/api/gameday/reactions/1'))['reactions'];
        $this->assertSame(['🔥'], array_column($reactions, 'e'));
        $this->assertSame(404, $this->get('/api/gameday/reactions/8')->getStatusCode());
    }

    #[Test]
    public function team_tags_are_for_admins_only()
    {
        $this->assertSame(403, $this->get('/api/gameday/team-tags', 2)->getStatusCode());
        $this->assertSame(403, $this->send($this->request('POST', '/api/gameday/team-tags', ['authenticatedAs' => 2, 'json' => ['mapping' => [1 => 5]]]))->getStatusCode());
        $this->assertSame(0, TeamTag::query()->count());

        $saved = $this->send($this->request('POST', '/api/gameday/team-tags', ['authenticatedAs' => 1, 'json' => ['mapping' => [1 => 5, 2 => 6]]]));
        $this->assertSame(2, $this->json($saved)['saved']);

        $this->send($this->request('POST', '/api/gameday/team-tags', ['authenticatedAs' => 1, 'json' => ['mapping' => [2 => 0]]]));

        $teams = array_column($this->json($this->get('/api/gameday/team-tags', 1))['data'], 'tagId', 'id');
        $this->assertSame(5, $teams[1]);
        $this->assertSame(0, $teams[2], 'A zero clears the mapping');
        $this->assertCount(20, $teams);
    }
}
