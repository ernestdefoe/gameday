<?php

declare(strict_types=1);

namespace ErnestDefoe\Gameday\Block;

use ErnestDefoe\Gameday\Service\Words;
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
        'passing' => ['sort' => 'YDS', 'group' => 'offence'],
        'rushing' => ['sort' => 'YDS', 'group' => 'offence'],
        'receiving' => ['sort' => 'YDS', 'group' => 'offence'],
        'defensive' => ['sort' => 'TOT', 'group' => 'defence'],
        'interceptions' => ['sort' => 'INT', 'group' => 'defence'],
        'kicking' => ['sort' => 'PTS', 'group' => 'special'],
        'punting' => ['sort' => 'AVG', 'group' => 'special'],
        'kickReturns' => ['sort' => 'YDS', 'group' => 'special'],
        'puntReturns' => ['sort' => 'YDS', 'group' => 'special'],
    ];

    /**
     * 🚨 Three groups now, and special teams is the reason the box score had to
     * change. ESPN names a single LEADER for four categories and none at all
     * for kicking, punting or returns — so a page built from `leaders` could
     * never show a kicker. Picks keeps the per-athlete lines now, and this
     * reads those where they exist and falls back to `leaders` where they do
     * not, which is every box score fetched before that change.
     */
    private const GROUPS = ['offence', 'defence', 'special'];

    public function __construct(protected ConnectionInterface $db)
    {
    }

    /**
     * How many finished games a week needs before it becomes THE week.
     *
     * Six is about a Thursday-and-Friday slate plus the early Saturday kicks —
     * enough that the leaders are a week's leaders rather than one game's.
     */
    private const WEEK_READY_GAMES = 6;

    public function type(): string
    {
        return 'gameday-performers';
    }

    public function name(): string
    {
        return Words::t('api.performers.name');
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
            ['key' => 'title', 'type' => 'text', 'label' => Words::t('api.performers.field_title'), 'default' => Words::t('api.performers.name')],
            ['key' => 'limit', 'type' => 'range', 'label' => Words::t('api.performers.field_limit'), 'default' => 5, 'min' => 1, 'max' => 10],
            [
                'key' => 'hideWhenEmpty',
                'type' => 'toggle',
                'label' => Words::t('api.performers.field_hide'),
                'default' => true,
                'help' => Words::t('api.performers.field_hide_help'),
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
         *
         * 🚨 And not the moment the FIRST one lands, either. A Thursday night
         * game finishing would otherwise flip the whole section to the new week
         * and stand one team's leaders up as the players of the week, where the
         * week has barely started — a worse answer than last week's finished
         * one, and it would sit there until Saturday night.
         *
         * So: the latest week with a real slate of results behind it, falling
         * back to the latest week with any at all, which is what carries a
         * championship weekend or a bowl week of three games.
         */
        $counts = $this->db->table('picks_box_scores as b')
            ->join('picks_events as e', 'e.id', '=', 'b.event_id')
            ->whereNotNull('e.week_id')
            ->groupBy('e.week_id')
            ->orderByRaw('MAX(e.match_date) DESC')
            ->get(['e.week_id', $this->db->raw('COUNT(*) as games')]);

        $week = null;

        foreach ($counts as $row) {
            if ((int) $row->games >= self::WEEK_READY_GAMES) {
                $week = $row->week_id;

                break;
            }
        }

        $week = $week ?: ($counts[0]->week_id ?? null);

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
                /*
                 * 🚨 `performers` where the box score has it, `leaders` where it
                 * does not. Every score fetched before Picks started keeping the
                 * per-athlete lines has only a leader per category — and those
                 * games are still worth ranking, they simply contribute one
                 * candidate each instead of five.
                 */
                $lines = $this->linesFor($payload[$side] ?? []);

                foreach ($lines as [$category, $name, $stats, $headshot]) {
                    $score = $this->score($category, $stats);

                    if ($name === '' || $score <= 0) {
                        continue;
                    }

                    $group = self::CATEGORIES[$category]['group'];

                    /*
                     * 🚨 Keyed by player AND category, so a quarterback who led
                     * both passing and rushing appears once for each — which is
                     * right, they are two performances — but the same line
                     * cannot arrive twice from two sides of one payload.
                     */
                    $candidates[$group][$this->key($name).'|'.$category] = [
                        'score' => $score,
                        'category' => $category,
                        'name' => $name,
                        'stats' => $stats,
                        'headshot' => $headshot,
                        'teamId' => (int) $row->{$column},
                    ];
                }
            }
        }

        $teams = [];

        foreach ($candidates as $list) {
            foreach ($list as $c) {
                $teams[] = $c['teamId'];
            }
        }

        $teams = $this->teams($teams);

        // Name AND club, because the photo fallback needs both — see photos().
        $people = [];

        foreach ($candidates as $list) {
            foreach ($list as $c) {
                $people[] = ['name' => $c['name'], 'abbr' => $teams[$c['teamId']]['abbr'] ?? ''];
            }
        }

        $photos = $this->photos($people);
        $colours = $this->colours(array_map(fn ($t) => $t['abbr'] ?? '', $teams));

        $groups = [];

        foreach (self::GROUPS as $key) {
            $title = Words::t('api.performers.groups.'.$key);

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

                [$big, $bigLabel] = $this->headline($c['category'], $c['stats']);

                $players[] = [
                    'rank' => ++$rank,
                    'name' => $c['name'],
                    'label' => Words::t('api.performers.categories.'.$c['category']),
                    'line' => $this->line($c['category'], $c['stats']),
                    'big' => $big,
                    'bigLabel' => $bigLabel,
                    // The club's own colour, for the card behind the player.
                    'color' => $colours[mb_strtoupper($team['abbr'] ?? '')] ?? null,
                    'team' => $team['name'] ?? '',
                    'teamAbbr' => $team['abbr'] ?? '',
                    'crest' => $team['crest'] ?? '',
                    /*
                     * 🚨 The feed's own headshot first. It comes with the stat
                     * line and is the same person by construction; the Roster
                     * match is a fallback for older box scores, and matching on
                     * a name is always a guess about punctuation.
                     */
                    'photo' => ($c['headshot'] ?? '') !== ''
                        ? $c['headshot']
                        : ($photos[$this->key($c['name'])] ?? null),
                ];
            }

            $groups[] = ['key' => $key, 'title' => $title, 'players' => $players];
        }

        return ['week' => $weekName ?: Words::t('api.performers.week', ['week' => (string) $week]), 'groups' => $groups];
    }

    /**
     * Every rankable line on one side of a box score.
     *
     * @return list<array{0:string,1:string,2:array,3:string}>
     */
    protected function linesFor(array $side): array
    {
        $out = [];

        foreach ((array) ($side['performers'] ?? []) as $category => $entries) {
            if (! isset(self::CATEGORIES[$category])) {
                continue;
            }

            foreach ((array) $entries as $entry) {
                $out[] = [
                    $category,
                    trim((string) ($entry['name'] ?? '')),
                    (array) ($entry['stats'] ?? []),
                    (string) ($entry['headshot'] ?? ''),
                ];
            }
        }

        if ($out !== []) {
            return $out;
        }

        foreach ((array) ($side['leaders'] ?? []) as $category => $leader) {
            if (! isset(self::CATEGORIES[$category]) || ! is_array($leader)) {
                continue;
            }

            $out[] = [
                $category,
                trim((string) ($leader['name'] ?? '')),
                (array) ($leader['stats'] ?? []),
                '',
            ];
        }

        return $out;
    }

    /**
     * How good a line is, as one number to sort on.
     *
     * 🚨 Weighted per category, not raw yards.
     *
     * The first version ranked a group on whichever single figure the category
     * happened to carry, and "top five offence" came back as five
     * quarterbacks — every time, because a passing day is measured in four
     * hundred yards and a rushing day in a hundred and fifty. Comparing them
     * unweighted does not rank players, it ranks positions.
     *
     * So: the ordinary fantasy weights, which exist precisely because they make
     * these categories comparable, and which anybody who plays fantasy football
     * can check at a glance. A passing yard is worth a quarter of a rushing
     * yard; a touchdown is worth six of anything. Defence and special teams get
     * the same treatment for the same reason — a linebacker's fourteen tackles
     * and a corner's pick-six are not the same number and should not be
     * compared as one.
     */
    protected function score(string $category, array $stats): float
    {
        $num = fn (string $key) => (float) preg_replace('/[^0-9.].*$/', '', ltrim((string) ($stats[$key] ?? '0')));

        return match ($category) {
            'passing' => $num('YDS') / 25 + $num('TD') * 4 - $num('INT') * 2,
            'rushing', 'receiving' => $num('YDS') / 10 + $num('TD') * 6,
            'defensive' => $num('TOT') + $num('SACKS') * 3 + $num('TFL') + $num('TD') * 6,
            'interceptions' => $num('INT') * 6 + $num('YDS') / 10 + $num('TD') * 6,
            // A kicker's afternoon IS his points; a long field goal is worth a
            // nod on top of the three it already scored.
            'kicking' => $num('PTS') + ($num('LONG') >= 50 ? 2 : 0),
            /*
             * 🚨 Punting measured against a baseline, not on its average. A
             * 44-yard average is a good day and a 30-yard one is not, but as a
             * raw number they are close enough that a punter would outrank a
             * kick returner's touchdown — so what is scored is how far above
             * an ordinary punt he was, plus the ones he pinned inside the 20.
             */
            'punting' => max(0, $num('AVG') - 40) + $num('In 20') * 2,
            'kickReturns', 'puntReturns' => $num('YDS') / 10 + $num('TD') * 6,
            default => 0.0,
        };
    }

    /** The stat line as a football page would write it. */
    protected function line(string $category, array $stats): string
    {
        $get = fn (string $k) => trim((string) ($stats[$k] ?? ''));

        return match ($category) {
            'passing' => trim($get('C/ATT').', '.$this->yds($get('YDS')).$this->tds($get('TD'))),
            'rushing' => trim($this->abbr('car', $get('CAR')).', '.$this->yds($get('YDS')).$this->tds($get('TD'))),
            'receiving' => trim($this->abbr('rec', $get('REC')).', '.$this->yds($get('YDS')).$this->tds($get('TD'))),
            // TOT is total tackles; SACKS and INT are the ones worth naming.
            'defensive' => trim(implode(', ', array_filter([
                $get('TOT') !== '' ? $this->abbr((int) $get('TOT') === 1 ? 'tackle' : 'tackles', $get('TOT')) : '',
                (float) $get('SACKS') > 0 ? $this->abbr((float) $get('SACKS') === 1.0 ? 'sack' : 'sacks', $get('SACKS')) : '',
                (int) $get('INT') > 0 ? $this->abbr('int', $get('INT')) : '',
            ]))),
            'interceptions' => trim($this->abbr('int', $get('INT')).', '.$this->yds($get('YDS')).$this->tds($get('TD'))),
            'kicking' => trim(implode(', ', array_filter([
                $get('FG') !== '' ? $this->abbr('fg', $get('FG')) : '',
                $get('LONG') !== '' ? $this->abbr('long', $get('LONG')) : '',
                $get('PTS') !== '' ? $this->abbr('pts', $get('PTS')) : '',
            ]))),
            'punting' => trim($this->abbr('punts', $get('NO')).', '.$this->abbr('avg', $get('AVG'))),
            'kickReturns', 'puntReturns' => trim($this->abbr('ret', $get('NO')).', '.$this->yds($get('YDS')).$this->tds($get('TD'))),
            default => '',
        };
    }

    /**
     * The one number a showcase card leads with, and what to call it.
     *
     * 🚨 A card has room for one big figure and the eye goes to it first, so it
     * has to be the figure that made the performance notable — yards for a
     * passer, tackles for a linebacker, points for a kicker. The full line is
     * still printed underneath; this is the headline, not a summary.
     *
     * @return array{0:string,1:string}
     */
    protected function headline(string $category, array $stats): array
    {
        $get = fn (string $k) => trim((string) ($stats[$k] ?? ''));

        return match ($category) {
            'passing', 'rushing', 'receiving', 'kickReturns', 'puntReturns' => [$get('YDS'), Words::t('api.performers.big.yards')],
            'defensive' => [$get('TOT'), Words::t('api.performers.big.tackles')],
            'interceptions' => [$get('INT'), Words::t((int) $get('INT') === 1 ? 'api.performers.big.interception' : 'api.performers.big.interceptions')],
            'kicking' => [$get('PTS'), Words::t('api.performers.big.points')],
            'punting' => [$get('AVG'), Words::t('api.performers.big.average')],
            default => ['', ''],
        };
    }

    protected function tds(string $td): string
    {
        return (int) $td > 0 ? ', '.$this->abbr('td', (string) (int) $td) : '';
    }

    /** "287 yds". */
    protected function yds(string $value): string
    {
        return $this->abbr('yds', $value);
    }

    /** A figure with its short unit after it: "3 TD", "long 52". */
    protected function abbr(string $unit, string $value): string
    {
        return Words::t('api.performers.line.'.$unit, ['n' => $value]);
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
     * @param  list<array{name: string, abbr: string}> $people
     * @return array<string, string>
     */
    protected function photos(array $people): array
    {
        $model = '\\ErnestDefoe\\Roster\\Player';

        if ($people === [] || ! class_exists($model)) {
            return [];
        }

        $names = array_values(array_unique(array_column($people, 'name')));
        $out = [];

        // The easy half: the two feeds wrote the same name.
        foreach ($model::query()->whereIn('name', $names)->get() as $player) {
            $photo = trim((string) ($player->photo_url ?? ''));

            if ($photo !== '') {
                $out[$this->key((string) $player->name)] = $photo;
            }
        }

        /*
         * 🚨 The hard half: the same player under a different first name.
         * CollegeFootballData writes "Samuel Omosigho" and ESPN's roster says
         * "Sammy" — a nickname, not a typo, and no amount of normalising the
         * string will bridge it.
         *
         * So the fallback is surname plus first initial, SCOPED TO THE CLUB.
         * Surname and initial alone is a real collision across fifteen thousand
         * players; the same club as well makes it a safe bet, and a wrong
         * headshot on a player-of-the-week card is worse than none.
         */
        $missing = [];

        foreach ($people as $person) {
            if (isset($out[$this->key($person['name'])])) {
                continue;
            }

            $parts = preg_split('/\s+/', trim((string) $person['name'])) ?: [];

            if (count($parts) < 2 || ($person['abbr'] ?? '') === '') {
                continue;
            }

            $missing[] = [
                'key' => $this->key($person['name']),
                'abbr' => mb_strtoupper((string) $person['abbr']),
                'initial' => mb_strtolower(mb_substr($parts[0], 0, 1)),
                'surname' => mb_strtolower(end($parts)),
            ];
        }

        if ($missing === []) {
            return $out;
        }

        $rows = $model::query()
            ->join('roster_teams', 'roster_teams.id', '=', 'roster_players.team_id')
            ->whereIn('roster_teams.abbreviation', array_unique(array_column($missing, 'abbr')))
            ->whereNotNull('roster_players.photo_url')
            ->where('roster_players.photo_url', '!=', '')
            ->get(['roster_players.name', 'roster_players.photo_url', 'roster_teams.abbreviation']);

        foreach ($missing as $want) {
            foreach ($rows as $row) {
                if (mb_strtoupper((string) $row->abbreviation) !== $want['abbr']) {
                    continue;
                }

                $parts = preg_split('/\s+/', trim((string) $row->name)) ?: [];

                if (count($parts) < 2) {
                    continue;
                }

                if (
                    mb_strtolower(end($parts)) === $want['surname']
                    && mb_strtolower(mb_substr($parts[0], 0, 1)) === $want['initial']
                ) {
                    $out[$want['key']] = (string) $row->photo_url;

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Club colours, where the Roster extension happens to hold them.
     *
     * 🚨 Matched on the ABBREVIATION, not the name. Picks calls a club "Georgia
     * Tech" and Roster calls it "Georgia Tech Yellow Jackets" — the same club,
     * two feeds, two conventions — and the short code is the one thing both
     * write identically.
     *
     * 🚨 Reached by class name, so a board without Roster gets cards in the
     * theme's own colours rather than a fatal.
     *
     * @return array<string, string>
     */
    protected function colours(array $abbrs): array
    {
        $model = '\\ErnestDefoe\\Roster\\Team';

        $abbrs = array_values(array_filter(array_unique($abbrs)));

        if ($abbrs === [] || ! class_exists($model)) {
            return [];
        }

        $out = [];

        foreach ($model::query()->whereIn('abbreviation', $abbrs)->get() as $team) {
            $colour = ltrim(trim((string) $team->color), '#');

            // Six hex digits or nothing: a half-written colour in a gradient is
            // a card that renders black.
            if (preg_match('/^[0-9a-f]{6}$/i', $colour)) {
                $out[mb_strtoupper((string) $team->abbreviation)] = '#'.$colour;
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
