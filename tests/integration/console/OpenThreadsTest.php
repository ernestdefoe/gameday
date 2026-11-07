<?php

namespace ErnestDefoe\Gameday\Tests\integration\console;

use Carbon\Carbon;
use ErnestDefoe\Gameday\Service\Threads;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use Resofire\Picks\PickEvent;

/*
 * Opening is a fresh question every minute: which games are inside the lead
 * time and have no thread? Nothing remembers having "already looked" at a game,
 * so raising the lead time opens the games it now covers on the next run.
 */
class OpenThreadsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks', 'ernestdefoe-gameday');
        $this->setting('ernestdefoe-gameday.author_id', 1);
        $this->setting('ernestdefoe-gameday.lead_minutes', 180);
    }

    private function games(array $games): void
    {
        $this->prepareDatabase([
            'picks_teams' => array_map(fn ($id) => ['id' => $id, 'name' => "Team $id", 'slug' => "team-$id", 'abbreviation' => "T$id", 'conference' => 'Conf'], range(1, 6)),
            'picks_seasons' => [['id' => 1, 'name' => '2026', 'slug' => '2026', 'year' => 2026]],
            'picks_weeks' => [['id' => 1, 'season_id' => 1, 'name' => 'Week 11', 'week_number' => 11]],
            'picks_events' => $games,
        ]);
    }

    private function game(int $id, Carbon $kickoff, int $home, int $away): array
    {
        return ['id' => $id, 'week_id' => 1, 'home_team_id' => $home, 'away_team_id' => $away, 'match_date' => $kickoff, 'cutoff_date' => $kickoff, 'status' => 'scheduled'];
    }

    private function threaded(): array
    {
        return $this->database()->table('gameday_threads')->orderBy('event_id')->pluck('event_id')->map(fn ($id) => (int) $id)->all();
    }

    #[Test]
    public function raising_the_lead_time_opens_games_it_now_covers()
    {
        $this->games([
            $this->game(1, Carbon::now()->addHours(2), 1, 2),
            $this->game(2, Carbon::now()->addHours(18), 3, 4),
            $this->game(3, Carbon::now()->addHours(40), 5, 6),
        ]);

        $threads = fn () => $this->app()->getContainer()->make(Threads::class);

        $this->assertSame(1, $threads()->open());
        $this->assertSame([1], $this->threaded());

        $lead = fn (int $minutes) => $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set('ernestdefoe-gameday.lead_minutes', $minutes);

        $lead(1440);
        $this->assertSame(1, $threads()->open(), 'The game 18 hours out, skipped at 180 minutes');

        $lead(2880);
        $this->assertSame(1, $threads()->open(), 'The game 40 hours out');
        $this->assertSame([1, 2, 3], $this->threaded());

        $this->assertSame(0, $threads()->open(), 'Every game has its one thread');
    }

    #[Test]
    public function a_game_that_cannot_be_opened_does_not_hold_back_later_ones()
    {
        $this->games([
            $this->game(1, Carbon::now()->addHour(), 1, 2),
            $this->game(2, Carbon::now()->addHours(2), 3, 4),
        ]);

        $opened = $this->app()->getContainer()->make(FailsOnGameOne::class)->open();

        $this->assertSame(1, $opened);
        $this->assertSame([2], $this->threaded());
    }
}

/** Stands in for any game whose thread throws while it is being written. */
class FailsOnGameOne extends Threads
{
    protected function openOne(PickEvent $game, User $author): bool
    {
        if ((int) $game->id === 1) {
            throw new \RuntimeException('This game cannot be opened.');
        }

        return parent::openOne($game, $author);
    }
}
