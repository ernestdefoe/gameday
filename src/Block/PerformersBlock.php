<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Block;

use Ernestdefoe\PageBuilder\Block\AbstractBlock;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * The players of the week, from the box scores.
 *
 * 🚨 Offence and DEFENCE, and no special teams. ESPN's box score names a leader
 * for passing, rushing, receiving and defence, and gives kicking and returns
 * only as team totals — there is no per-player special-teams leader in the
 * data. A fourth card headed "Special teams" would have to be computed from a
 * number that belongs to eleven people, so it is not offered rather than
 * offered wrong.
 *
 * 🚨 Only ever loaded where Page Builder is installed; see extend.php.
 */
class PerformersBlock extends AbstractBlock
{
    /** ESPN's leader categories, in the order a football page reads them. */
    private const CATEGORIES = [
        'passing' => ['label' => 'Passing', 'sort' => 'YDS', 'group' => 'offence'],
        'rushing' => ['label' => 'Rushing', 'sort' => 'YDS', 'group' => 'offence'],
        'receiving' => ['label' => 'Receiving', 'sort' => 'YDS', 'group' => 'offence'],
        'defensive' => ['label' => 'Defence', 'sort' => 'TOT', 'group' => 'defence'],
    ];

    /**
     * 🚨 Two groups, not three. ESPN names a leader for passing, rushing,
     * receiving and defence, and gives kicking and returns only as TEAM totals
     * — there is no per-player special-teams line in what Picks stores. A third
     * column headed "Special teams" would have to be computed from a number
     * belonging to eleven people, so it is absent rather than invented.
     *
     * The data does exist upstream: ESPN's game summary carries per-player
     * kicking and punting under `boxscore.players`, which Picks fetches and
     * does not keep. Capturing it is what a special-teams column needs.
     */
    private const GROUPS = [
        'offence' => 'Offence',
        'defence' => 'Defence',
    ];

    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function type(): string
    {
        return 'gameday-performers';
    }

    public function name(): string
    {
        return 'Players of the week';
    }

    public function icon(): string
    {
        return 'fas fa-star';
    }

    public function category(): string
    {
        return 'forum';
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => 'Players of the week'],
            ['key' => 'limit', 'type' => 'range', 'label' => 'How many per group', 'default' => 5, 'min' => 1, 'max' => 10],
            [
                'key' => 'hideWhenEmpty',
                'type' => 'toggle',
                'label' => 'Hide until there are box scores',
                'default' => true,
                'help' => 'Box scores arrive after a game finishes, and only for games Game Day opened a thread for.',
            ],
        ];
    }

    public function resolve(array $settings, User $actor): array
    {
        if (! $this->db->getSchemaBuilder()->hasTable('picks_box_scores')) {
            return ['week' => null, 'groups' => []];
        }

        $limit = max(1, min((int) ($settings['limit'] ?? 5), 10));

        /*
         * 🚨 The most recent week that HAS box scores, not the current week.
         * They arrive hours after a game finishes, so "this week" is empty all
         * of Saturday — and a section that empties itself on the one day
         * everybody is looking at it is worse than one showing last week's.
         */
        $week = $this->db->table('picks_box_scores as b')
            ->join('picks_events as e', 'e.id', '=', 'b.event_id')
            ->whereNotNull('e.week_id')
            ->orderByDesc('e.match_date')
            ->value('e.week_id');

        if (! $week) {
            return ['week' => null, 'groups' => []];
        }

        $rows = $this->db->table('picks_box_scores as b')
            ->join('picks_events as e', 'e.id', '=', 'b.event_id')
            ->join('picks_weeks as w', 'w.id', '=', 'e.week_id')
            ->where('e.week_id', $week)
            ->get(['b.payload', 'e.home_team_id', 'e.away_team_id', 'w.name as week_name']);

        $candidates = [];
        $weekName = '';

        foreach ($rows as $row) {
            $weekName = $weekName ?: (string) $row->week_name;
            $payload = json_decode((string) $row->payload, true);

            if (! is_array($payload)) {
                continue;
            }

            foreach (['home' => 'home_team_id', 'away' => 'away_team_id'] as $side => $column) {
                foreach ((array) ($payload[$side]['leaders'] ?? []) as $category => $leader) {
                    if (! isset(self::CATEGORIES[$category]) || ! is_array($leader)) {
                        continue;
                    }

                    $name = trim((string) ($leader['name'] ?? ''));
                    $stats = (array) ($leader['stats'] ?? []);
                    $score = $this->score($category, $stats);

                    if ($name === '' || $score <= 0) {
                        continue;
                    }

                    $group = self::CATEGORIES[$category]['group'];

                    /*
                     * 🚨 Keyed by player AND category, so a quarterback who led
                     * both passing and rushing appears once for each — which is
                     * right, they are two performances — but the same passing
                     * line cannot arrive twice from two sides of one payload.
                     */
                    $candidates[$group][$this->key($name) . '|' . $category] = [
                        'score' => $score,
                        'category' => $category,
                        'label' => self::CATEGORIES[$category]['label'],
                        'name' => $name,
                        'stats' => $stats,
                        'teamId' => (int) $row->{$column},
                    ];
                }
            }
        }

        $teams = [];
        $names = [];

        foreach ($candidates as $list) {
            foreach ($list as $c) {
                $teams[] = $c['teamId'];
                $names[] = $c['name'];
            }
        }

        $teams = $this->teams($teams);
        $photos = $this->photos($names);

        $groups = [];

        foreach (self::GROUPS as $key => $title) {
            $list = array_values($candidates[$key] ?? []);

            if ($list === []) {
                continue;
            }

            /*
             * 🚨 Ranked WITHIN a group, never across them. Three hundred
             * passing yards and eleven tackles are not comparable numbers, so
             * offence is sorted on the yardage score and defence on its own —
             * pooling them would rank every quarterback above every linebacker
             * and call it a leaderboard.
             */
            usort($list, fn ($a, $b) => $b['score'] <=> $a['score']);

            $players = [];
            $rank = 0;

            foreach (array_slice($list, 0, $limit) as $c) {
                $team = $teams[$c['teamId']] ?? null;

                $players[] = [
                    'rank' => ++$rank,
                    'name' => $c['name'],
                    'label' => $c['label'],
                    'line' => $this->line($c['category'], $c['stats']),
                    'team' => $team['name'] ?? '',
                    'teamAbbr' => $team['abbr'] ?? '',
                    'crest' => $team['crest'] ?? '',
                    'photo' => $photos[$this->key($c['name'])] ?? null,
                ];
            }

            $groups[] = ['key' => $key, 'title' => $title, 'players' => $players];
        }

        return ['week' => $weekName ?: ('Week ' . $week), 'groups' => $groups];
    }

    /**
     * How good a line is, as one number to sort on.
     *
     * 🚨 Yards for the three offensive categories and tackles for defence —
     * comparing a linebacker's tackles against a quarterback's yards would
     * always give the quarterback, which is why the categories are ranked
     * separately rather than pooled.
     */
    protected function score(string $category, array $stats): float
    {
        $field = self::CATEGORIES[$category]['sort'];

        return (float) preg_replace('/[^0-9.]/', '', (string) ($stats[$field] ?? '0'));
    }

    /** The stat line as a football page would write it. */
    protected function line(string $category, array $stats): string
    {
        $get = fn (string $k) => trim((string) ($stats[$k] ?? ''));

        return match ($category) {
            'passing' => trim(sprintf('%s, %s yds%s', $get('C/ATT'), $get('YDS'), $this->tds($get('TD')))),
            'rushing' => trim(sprintf('%s car, %s yds%s', $get('CAR'), $get('YDS'), $this->tds($get('TD')))),
            'receiving' => trim(sprintf('%s rec, %s yds%s', $get('REC'), $get('YDS'), $this->tds($get('TD')))),
            // TOT is total tackles; SACKS and INT are the ones worth naming.
            'defensive' => trim(implode(', ', array_filter([
                $get('TOT') !== '' ? $get('TOT') . ((int) $get('TOT') === 1 ? ' tackle' : ' tackles') : '',
                (float) $get('SACKS') > 0 ? $get('SACKS') . ((float) $get('SACKS') === 1.0 ? ' sack' : ' sacks') : '',
                (int) $get('INT') > 0 ? $get('INT') . ' INT' : '',
            ]))),
            default => '',
        };
    }

    protected function tds(string $td): string
    {
        return (int) $td > 0 ? ', ' . (int) $td . ' TD' : '';
    }

    /**
     * The clubs those players play for.
     *
     * @return array<int, array<string, string>>
     */
    protected function teams(array $ids): array
    {
        $model = '\\Resofire\\Picks\\Team';

        if ($ids === [] || ! class_exists($model)) {
            return [];
        }

        $out = [];

        foreach ($model::query()->whereIn('id', array_unique($ids))->get() as $team) {
            $out[(int) $team->id] = [
                'name' => (string) $team->name,
                'abbr' => (string) $team->abbreviation,
                // The dark-ground crest: these cards are dark in both themes.
                'crest' => (string) ($team->logo_dark_url ?? $team->logo_url ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Headshots, where the Roster extension happens to have one.
     *
     * 🚨 Matched on a NORMALISED name, because the two feeds do not agree about
     * punctuation — ESPN's box score writes "Demond Williams Jr." and a roster
     * row may carry "Demond Williams Jr". Reached by class name so a board
     * without Roster gets cards with no photograph rather than a fatal.
     *
     * @return array<string, string>
     */
    protected function photos(array $names): array
    {
        $model = '\\ErnestDefoe\\Roster\\Player';

        if ($names === [] || ! class_exists($model)) {
            return [];
        }

        $out = [];

        foreach ($model::query()->whereIn('name', $names)->get() as $player) {
            $photo = trim((string) ($player->photo_url ?? ''));

            if ($photo !== '') {
                $out[$this->key((string) $player->name)] = $photo;
            }
        }

        return $out;
    }

    /** A name reduced to what two feeds can be expected to agree on. */
    protected function key(string $name): string
    {
        return preg_replace('/[^a-z]/', '', mb_strtolower($name)) ?: $name;
    }
}
