<?php

declare(strict_types=1);

/*
 * The recap, asserted on the WORDS.
 *
 * 🚨 This is the SIBLING of `tests/RecapTest.php` in the Convoro build, and the
 * sentences it asserts are deliberately the same ones. The two Recaps are meant
 * to say identical things in different markup; without a test on each side, the
 * only thing keeping them together is somebody remembering to edit both.
 *
 * 🚨 Plain PHP, no PHPUnit. No Flarum extension in this set carries a dev
 * toolchain, and a guard that needs `composer install --dev` before it runs is
 * a guard nobody runs. Everything under test is a pure function, so:
 *
 *     php tests/run.php
 *
 * Nothing here touches the database, the network or Flarum itself.
 */

require __DIR__ . '/../src/Service/Sports/Sport.php';
require __DIR__ . '/../src/Service/Sports/Gridiron.php';
require __DIR__ . '/../src/Service/Sports/Soccer.php';
require __DIR__ . '/../src/Service/Sports/Hardwood.php';
require __DIR__ . '/../src/Service/Sports/Diamond.php';
require __DIR__ . '/../src/Service/Sports/Ice.php';
require __DIR__ . '/../src/Service/Sports/Sports.php';
require __DIR__ . '/../src/Service/Recap.php';
require __DIR__ . '/../src/Service/Preview.php';

use ErnestDefoe\Gameday\Service\Preview;
use ErnestDefoe\Gameday\Service\Recap;
use ErnestDefoe\Gameday\Service\Sports\Diamond;
use ErnestDefoe\Gameday\Service\Sports\Gridiron;
use ErnestDefoe\Gameday\Service\Sports\Hardwood;
use ErnestDefoe\Gameday\Service\Sports\Ice;
use ErnestDefoe\Gameday\Service\Sports\Soccer;
use ErnestDefoe\Gameday\Service\Sports\Sports;

/* --------------------------------------------------------------- the harness */

$failures = [];
$passed = 0;

function ok(bool $condition, string $why, string $context = ''): void
{
    global $failures;

    if (!$condition) {
        $failures[] = $why . ($context === '' ? '' : "\n    ---\n    " . str_replace("\n", "\n    ", $context));
    }
}

function same($expected, $actual, string $why): void
{
    ok($expected === $actual, $why . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
}

/* ------------------------------------------------------------- the box score */

/*
 * 🚨 The REAL box score Picks stores, normalised by Picks' own code rather than
 * hand-written here. A fixture somebody typed only proves the recap agrees with
 * whoever typed it; this one is CollegeFootballData's answer for Notre Dame 41,
 * Wisconsin 13, week one of 2026.
 */
$box = json_decode((string) file_get_contents(__DIR__ . '/fixtures/normalised-box-score.json'), true);
// `fixtures/regenerate.php` rebuilds it from `cfbd-box-score.json` — the raw
// provider answer, kept beside it so the normalised one is never hand-edited.

$game = [
    'home_name' => 'Notre Dame',
    'away_name' => 'Wisconsin',
    'home_score' => 41,
    'away_score' => 13,
];

/* ------------------------------------------------------------------ the tests */

$tests = [];

$tests['a game with no box score still gets its recap'] = function () use ($game) {
    /*
     * 🚨 The behaviour every game had before statistics existed, and the one a
     * game the provider never covered still gets. A recap that depends on a box
     * score is a thread with no ending on the day the feed is late.
     */
    $text = (new Recap())->text($game, null);

    ok(str_contains($text, 'Final: Notre Dame 41, Wisconsin 13.'), 'the score is missing', $text);
    ok(str_contains($text, 'still here, still searchable'), 'the closing line is missing', $text);
    ok(!str_contains($text, 'out-gained'), 'nothing may be claimed about a game nobody has figures for');
};

$tests['the margin is described rather than just stated'] = function () {
    $recap = new Recap();
    $say = static fn (int $home, int $away): string => $recap->text([
        'home_name' => 'Alabama', 'away_name' => 'Auburn',
        'home_score' => $home, 'away_score' => $away,
    ], null);

    // "Won it" is equally true of a one-point game and a fifty-point one,
    // which makes it worth nothing in either.
    ok(str_contains($say(24, 21), 'Alabama took it by 3.'), 'a three-point game');
    ok(str_contains($say(31, 20), 'Alabama won it by 11.'), 'an eleven-point game');
    ok(str_contains($say(38, 17), 'Alabama had it comfortably.'), 'a comfortable game');
    ok(str_contains($say(52, 0), 'Alabama were never troubled.'), 'a rout');

    /*
     * 🚨 Remarked on rather than reported. A drawn game of American football
     * needs a full overtime that settles nothing, and saying "it finished
     * level" as flatly as a soccer recap would understate the strangest result
     * the sport has.
     */
    ok(str_contains($say(21, 21), 'It finished level, which almost never happens.'), 'a tie');
};

$tests['it says how the game was won'] = function () use ($game, $box) {
    $text = (new Recap())->text($game, $box);

    ok(str_contains($text, 'Notre Dame out-gained Wisconsin 350 to 284.'), 'the yardage', $text);
    ok(str_contains($text, 'Wisconsin gave it away twice, Notre Dame not at all.'), 'the turnovers', $text);
};

$tests['a close game does not claim somebody out-gained anybody'] = function () {
    /*
     * 🚨 Thirty yards apart is not a team being out-gained, and saying so
     * implies the game was won there. The threshold exists because the sentence
     * is a claim, not a subtraction.
     */
    $text = (new Recap())->text(
        ['home_name' => 'Ohio State', 'away_name' => 'Michigan', 'home_score' => 20, 'away_score' => 17],
        [
            'home' => ['team' => 'Ohio State', 'points' => 20, 'stats' => ['totalYards' => '331'], 'leaders' => []],
            'away' => ['team' => 'Michigan', 'points' => 17, 'stats' => ['totalYards' => '308'], 'leaders' => []],
        ],
    );

    ok(!str_contains($text, 'out-gained'), 'thirty yards is not being out-gained', $text);
    ok(str_contains($text, 'There was almost nothing in the yardage — 331 to 308.'), 'the even wording', $text);
};

$tests['a clean game says nothing about turnovers'] = function () {
    // Nobody lost the ball, so there is nothing to say — and a recap that
    // always has a turnover sentence has one of nothing most weeks.
    $text = (new Recap())->text(
        ['home_name' => 'Iowa', 'away_name' => 'Purdue', 'home_score' => 14, 'away_score' => 7],
        [
            'home' => ['team' => 'Iowa', 'points' => 14, 'stats' => ['turnovers' => '0'], 'leaders' => []],
            'away' => ['team' => 'Purdue', 'points' => 7, 'stats' => ['turnovers' => '0'], 'leaders' => []],
        ],
    );

    ok(!str_contains($text, 'gave it away'), 'a clean game invented a turnover sentence', $text);
};

$tests['the players are named the way somebody would say it'] = function () use ($game, $box) {
    $text = (new Recap())->text($game, $box);

    ok(str_contains($text, 'C.J. Carr 19/29 for 239 and two touchdowns'), 'the quarterback', $text);
    ok(str_contains($text, 'Aneyas Williams 20 carries for 90 and two touchdowns'), 'the running back', $text);
    ok(str_contains($text, 'Jordan Faison 4 catches for 81'), 'the receiver', $text);

    /*
     * 🚨 "With once picked off" is what counting words give you when they are
     * reused for something that is not a count of occasions, and it reads as a
     * typo. Interceptions get their own wording.
     */
    ok(str_contains($text, 'and a touchdown, with an interception'), 'the interception wording', $text);
    ok(!str_contains($text, 'once picked off'), 'counting words leaked into interceptions');
};

$tests['a player who leads two categories is named once'] = function () {
    /*
     * 🚨 A dual-threat quarterback is ordinary in college football, and before
     * this the recap named him twice in the same sentence as though he were two
     * people. Found on a real game — Washington's Demond Williams Jr., who led
     * both passing and rushing — the day this shipped.
     */
    $text = (new Recap())->text(
        ['home_name' => 'Washington', 'away_name' => 'Washington State',
         'home_score' => 24, 'away_score' => 10],
        [
            'home' => [
                'team' => 'Washington', 'points' => 24,
                'stats' => ['totalYards' => '402'],
                'leaders' => [
                    'passing' => ['name' => 'Demond Williams Jr.',
                        'stats' => ['C/ATT' => '24/35', 'YDS' => '268', 'TD' => '1', 'INT' => '0']],
                    'rushing' => ['name' => 'Demond Williams Jr.',
                        'stats' => ['CAR' => '7', 'YDS' => '61', 'TD' => '1']],
                    'receiving' => ['name' => 'Chris Lawson',
                        'stats' => ['REC' => '4', 'YDS' => '87', 'TD' => '0']],
                ],
            ],
            'away' => ['team' => 'Washington State', 'points' => 10,
                'stats' => ['totalYards' => '242'], 'leaders' => []],
        ],
    );

    ok(str_contains(
        $text,
        'Demond Williams Jr. 24/35 for 268 and a touchdown, and 7 carries for 61 and a touchdown'
    ), 'the two lines were not joined', $text);

    same(1, substr_count($text, 'Demond Williams Jr.'), 'named once, not once per category');
    ok(str_contains($text, 'Chris Lawson 4 catches for 87'), 'and everybody else still appears', $text);
};

$tests['the comparison carries the figures that explain a game'] = function () use ($game, $box) {
    $text = (new Recap())->text($game, $box);

    foreach (['First downs', 'Total yards', 'Third down', 'Turnovers', 'Possession'] as $row) {
        ok(str_contains($text, $row), $row . ' is missing from the comparison');
    }

    // The ratio and the clock survive as themselves rather than as numbers.
    ok(str_contains($text, '3-9'), 'the third-down ratio was mangled', $text);
    ok(str_contains($text, '31:36'), 'the possession clock was mangled', $text);

    /*
     * 🚨 And NOT a table. Convoro's recap draws one because it can; a Markdown
     * table here needs an extension most boards do not have, and the failure
     * mode is a screenful of pipes.
     */
    ok(!str_contains($text, '|---'), 'a Markdown table leaked into a Flarum recap', $text);
};

$tests['a row neither side has a figure for is not drawn'] = function () use ($game) {
    $text = (new Recap())->text($game, [
        'home' => ['team' => 'A', 'points' => 7, 'stats' => ['totalYards' => '200'], 'leaders' => []],
        'away' => ['team' => 'B', 'points' => 3, 'stats' => ['totalYards' => '180'], 'leaders' => []],
    ]);

    ok(str_contains($text, 'Total yards'), 'the row that has figures is missing', $text);
    ok(!str_contains($text, 'Possession'), 'an empty row is worse than a missing one', $text);
};

$tests['the emphasis style is the caller\'s, and none is a real answer'] = function () use ($game) {
    /*
     * 🚨 A recap written in Markdown on a board with no Markdown extension
     * shows literal `**` to every reader — that has happened here before.
     */
    ok(str_contains((new Recap(Recap::EMPHASIS_MARKDOWN))->text($game, null), '**Final: Notre Dame 41'), 'markdown');
    ok(str_contains((new Recap(Recap::EMPHASIS_BBCODE))->text($game, null), '[b]Final: Notre Dame 41'), 'bbcode');

    $plain = (new Recap())->text($game, null);
    ok(str_contains($plain, 'Final: Notre Dame 41'), 'plain still says it');
    ok(!str_contains($plain, '**') && !str_contains($plain, '[b]'), 'plain leaked markup', $plain);
};

/*
 * 🚨 The seam, proved against the sport that disagrees with gridiron about
 * nearly everything a recap says.
 *
 * The statistic names and the figures below are ESPN's own, from a real
 * Everton 2–2 Manchester United box score — not invented, because a made-up
 * payload only proves the code agrees with whoever made it up.
 */
$tests['a draw in football is an ordinary result, not a curiosity'] = function () {
    $soccer = new Recap(Recap::EMPHASIS_NONE, new Soccer());

    $text = $soccer->text(
        ['home_name' => 'Everton', 'away_name' => 'Manchester United',
         'home_score' => 2, 'away_score' => 2],
        [
            'home' => ['team' => 'Everton', 'points' => 2, 'leaders' => [], 'stats' => [
                'possessionPct' => '45.4', 'totalShots' => '18', 'shotsOnTarget' => '6',
                'wonCorners' => '4', 'yellowCards' => '3', 'saves' => '1',
            ]],
            'away' => ['team' => 'Manchester United', 'points' => 2, 'leaders' => [], 'stats' => [
                'possessionPct' => '54.6', 'totalShots' => '9', 'shotsOnTarget' => '4',
                'wonCorners' => '2', 'yellowCards' => '1', 'saves' => '4',
            ]],
        ],
    );

    ok(str_contains($text, 'A draw.'), 'the result', $text);

    // 🚨 And NOT the gridiron wording. "It finished level, which almost never
    // happens" is true of American football and absurd here.
    ok(!str_contains($text, 'almost never happens'), 'gridiron wording leaked into a football match', $text);

    ok(str_contains($text, "Everton had 18 shots to Manchester United's 9, 6 on target against 4."), 'the shots', $text);
    ok(str_contains($text, 'Possession'), "the comparison is the sport's own", $text);

    /*
     * 🚨 The unit lives with the sport. ESPN answers a possession share as
     * `45.4` and a shot count as `18`; printing both bare makes the first look
     * like a count of something.
     */
    ok(str_contains($text, '45.4%'), 'the possession share lost its unit', $text);
    ok(str_contains($text, 'Yellow cards'), 'a football row is missing', $text);
    ok(!str_contains($text, 'Total yards'), "and it carries none of gridiron's", $text);
};

$tests['a possession share is only remarked on when it was lopsided'] = function () {
    $soccer = new Recap(Recap::EMPHASIS_NONE, new Soccer());

    $even = $soccer->text(
        ['home_name' => 'A', 'away_name' => 'B', 'home_score' => 1, 'away_score' => 0],
        ['home' => ['stats' => ['possessionPct' => '52.0'], 'leaders' => []],
         'away' => ['stats' => ['possessionPct' => '48.0'], 'leaders' => []]],
    );

    // Fifty-two per cent of the ball is not a fact about a match, and a recap
    // that reports it every week teaches people to stop reading.
    ok(!str_contains($even, 'of the ball'), 'an even share was remarked on', $even);

    $lopsided = $soccer->text(
        ['home_name' => 'A', 'away_name' => 'B', 'home_score' => 1, 'away_score' => 0],
        ['home' => ['stats' => ['possessionPct' => '67.5'], 'leaders' => []],
         'away' => ['stats' => ['possessionPct' => '32.5'], 'leaders' => []]],
    );

    ok(str_contains($lopsided, 'A had 67.5% of the ball.'), 'a lopsided share went unremarked', $lopsided);
};

$tests['a sport with no player box score renders no empty line'] = function () {
    /*
     * 🚨 ESPN's soccer summary carries `boxscore.teams` and nothing else —
     * there is no player breakdown to name a scorer from. Declaring a category
     * that is always empty would put a heading over nothing under every match.
     */
    same([], (new Soccer())->leaderCategories(), 'soccer declared a player category');

    $text = (new Recap(Recap::EMPHASIS_NONE, new Soccer()))->text(
        ['home_name' => 'A', 'away_name' => 'B', 'home_score' => 1, 'away_score' => 0],
        ['home' => ['stats' => ['totalShots' => '9'], 'leaders' => []],
         'away' => ['stats' => ['totalShots' => '4'], 'leaders' => []]],
    );

    ok(!str_contains($text, "A — \n"), 'an empty leader line was written', $text);
};

/*
 * 🚨 The three sports added after the seam existed, each asserted against a
 * REAL ESPN box score run through Picks' own adapter and normaliser — a
 * Timberwolves/Bucks game, Braves/Phillies, Stars/Sabres. ESPN publishes no
 * documentation for that API, and the four shape differences the adapter
 * absorbs are exactly the ones nobody would think to invent, so a hand-written
 * payload here would prove nothing at all.
 *
 * `tests/fixtures/regenerate.php` rebuilds them.
 */
$espn = static fn (string $sport): array => json_decode(
    (string) file_get_contents(__DIR__ . '/fixtures/espn-' . $sport . '.json'),
    true
);

$tests['basketball is described in basketball\'s words'] = function () use ($espn) {
    $text = (new Recap(Recap::EMPHASIS_NONE, new Hardwood()))->text(
        ['home_name' => 'Milwaukee Bucks', 'away_name' => 'Minnesota Timberwolves',
         'home_score' => 103, 'away_score' => 106],
        $espn('nba'),
    );

    /*
     * 🚨 Three points is a rout in football and a coin toss here. Reusing
     * gridiron's thresholds would call every basketball game comfortable.
     */
    ok(str_contains($text, 'Minnesota Timberwolves got out with it, by 3.'), 'the margin', $text);
    ok(!str_contains($text, 'were never troubled'), 'a three-point game read as a rout', $text);

    // The one line basketball has that nothing else does.
    ok(str_contains($text, 'Giannis Antetokounmpo 23 points, 13 rebounds, 10 assists — a triple-double'), 'the triple-double', $text);

    /*
     * 🚨 And a quiet line stays quiet. Every basketball line would otherwise
     * read "25 points, 3 rebounds, 2 assists" — three facts where one was
     * interesting.
     */
    ok(str_contains($text, 'Anthony Edwards 25 points.'), 'a plain scoring line', $text);

    ok(str_contains($text, 'Points in the paint'), 'the comparison is basketball\'s own', $text);
    ok(!str_contains($text, 'Total yards'), 'gridiron vocabulary leaked in', $text);
};

$tests['baseball reads the group a figure came from'] = function () use ($espn) {
    $text = (new Recap(Recap::EMPHASIS_NONE, new Diamond()))->text(
        ['home_name' => 'Philadelphia Phillies', 'away_name' => 'Atlanta Braves',
         'home_score' => 1, 'away_score' => 0],
        $espn('mlb'),
    );

    ok(str_contains($text, 'Philadelphia Phillies took it by one.'), 'the margin', $text);
    ok(str_contains($text, 'Philadelphia Phillies went deep once.'), 'the home run', $text);

    // Batting and pitching are different jobs and read differently.
    ok(str_contains($text, 'Kyle Schwarber 2 for 4, a home run, 1 driven in'), 'the batting line', $text);
    ok(str_contains($text, 'Jesus Luzardo 9.0 innings, no earned runs, 12 struck out'), 'the pitching line', $text);

    /*
     * 🚨 The prefix, proved end to end. `hits` means three different things in
     * ESPN's three baseball groups, and unprefixed the fielding figure would be
     * printed as the batting line — a number that looks entirely plausible and
     * is about somebody else. Four hits and two, not the fielding zeros.
     */
    ok(str_contains($text, 'Hits — 4 / 2'), 'the batting hits', $text);
    ok(str_contains($text, 'Errors — 0 / 0'), 'the fielding errors', $text);

    /*
     * 🚨 A hitless night with nothing driven in is not worth a name. The
     * batting leader is whoever drove in the most runs, and in a 1–0 game that
     * is everybody, tied on nothing — so the first name in the order won it and
     * "Ronald Acuna Jr. 0 for 3" was printed as though it were the highlight.
     */
    ok(!str_contains($text, '0 for 3'), 'a quiet night was named as a highlight', $text);
};

$tests['hockey names the goaltender and only the groups with people in them'] = function () use ($espn) {
    $text = (new Recap(Recap::EMPHASIS_NONE, new Ice()))->text(
        ['home_name' => 'Buffalo Sabres', 'away_name' => 'Dallas Stars',
         'home_score' => 2, 'away_score' => 3],
        $espn('nhl'),
    );

    ok(str_contains($text, 'Dallas Stars took it by one.'), 'the margin', $text);

    /*
     * 🚨 A whole sentence per case rather than clauses joined with a comma.
     * Built up from parts, the night only the away side converted produced
     * "Dallas Stars once." — the second clause leaning on a first that was
     * never added.
     */
    ok(str_contains($text, 'Dallas Stars scored once on the power play.'), 'the power play', $text);
    ok(!str_contains($text, "Stars once."), 'the sentence lost its verb', $text);

    ok(str_contains($text, 'Jake Oettinger 21 saves'), 'the goaltender', $text);
    ok(str_contains($text, 'a goal and an assist'), 'a skater', $text);

    // 🚨 ESPN's fourth hockey group, `skaters`, has labels and no athletes.
    ok(!str_contains($text, 'skaters'), 'an empty group reached the prose', $text);
};

$tests['an NFL game needs no new words at all'] = function () use ($espn) {
    /*
     * 🚨 The seam's best evidence. ESPN's NFL statistic names are IDENTICAL to
     * CollegeFootballData's — `totalYards`, `thirdDownEff`, `possessionTime` —
     * and so are its player labels, so a professional game is described by the
     * college vocabulary that already existed, with nothing written for it.
     */
    $text = (new Recap())->text(
        ['home_name' => 'Green Bay Packers', 'away_name' => 'Washington Commanders',
         'home_score' => 27, 'away_score' => 18],
        $espn('nfl'),
    );

    ok(str_contains($text, 'Green Bay Packers out-gained Washington Commanders 404 to 230.'), 'the yardage', $text);
    ok(str_contains($text, 'Jordan Love 19/31 for 292 and two touchdowns'), 'the quarterback', $text);
    ok(str_contains($text, 'Tucker Kraft 6 catches for 124 and a touchdown'), 'the receiver', $text);
    ok(str_contains($text, 'Possession — 32:26 / 27:34'), 'the possession clock', $text);
};

$tests['an unknown sport falls back rather than throwing'] = function () {
    /*
     * A settings value naming a sport that has been removed is somebody's
     * install, not a programming error — and a recap in the wrong vocabulary is
     * a far better outcome than a scheduled job that dies.
     */
    $sports = new Sports();

    same('gridiron', $sports->get('quidditch')->key(), 'an unknown key did not fall back');
    same('soccer', $sports->get('soccer')->key(), 'a known key did not resolve');
    ok(array_key_exists('gridiron', $sports->choices()), 'the dropdown lost its default');
    ok(count($sports->choices()) >= 2, 'the registry lost a sport');
};

$tests['a box score with nothing in it is treated as no box score'] = function () {
    $recap = new Recap();

    ok(!$recap->usable(null), 'null is not a box score');
    ok(!$recap->usable(['home' => ['stats' => []], 'away' => ['stats' => []]]), 'two empty sides are not a box score');
    ok(!$recap->usable(['home' => ['stats' => ['totalYards' => '1']]]), 'one side is not a box score');
    ok($recap->usable([
        'home' => ['stats' => ['totalYards' => '350']],
        'away' => ['stats' => ['totalYards' => '284']],
    ]), 'a real box score was rejected');
};

/* ------------------------------------------------------------- the preview */

/*
 * 🚨 The opening post, asserted on the WORDS, exactly as the recap is.
 *
 * This is the post that used to be one sentence of relative time, and the thing
 * that made it bad was not that it was short — it was that it said things which
 * stopped being true. So what is asserted here is mostly what the preview
 * LEAVES OUT when the feed did not supply it, because that is the failure the
 * old one could not have and this one can: a paragraph of dashes and commas
 * with no facts between them.
 */

/** A game the feed covered fully. */
function fixture(array $overrides = []): array
{
    return $overrides + [
        'home_name' => 'Kentucky',
        'away_name' => 'Alabama',
        'home_rank' => 0,
        'away_rank' => 12,
        'home_record' => '1-0',
        'away_record' => '1-0',
        'home_conference' => 'SEC',
        'away_conference' => 'SEC',
        'neutral_site' => false,
        'kickoff' => new DateTimeImmutable('2026-09-12T19:30:00+00:00'),
        'venue' => 'Kroger Field',
        'venue_city' => 'Lexington, KY',
        'broadcast' => 'ABC',
        'week' => 'Week 2',
    ];
}

$tests['a full fixture is previewed with everything it was given'] = function () {
    $text = (new Preview(Recap::EMPHASIS_MARKDOWN))->text(fixture());

    ok(str_contains($text, '**No. 12 Alabama at Kentucky** — Week 2'), 'the headline lost the rank or the week', $text);
    ok(str_contains($text, 'Kickoff is 3:30pm EDT on Saturday 12 September'), 'the kickoff was not spelled out', $text);
    ok(str_contains($text, ', at Kroger Field, Lexington, KY.'), 'the venue went missing', $text);
    ok(str_contains($text, 'On ABC.'), 'the channel went missing', $text);
    ok(str_contains($text, 'Both come in unbeaten'), 'two unbeaten sides were not noticed', $text);
    ok(str_contains($text, 'It is an SEC game.'), 'the conference game was not spotted, or took the wrong article', $text);

    /*
     * 🚨 The headline already carries "#12". A second paragraph saying which
     * side is ranked is the preview reading itself back, which is exactly how
     * an automated post starts sounding like one.
     */
    ok(!str_contains($text, 'are ranked'), 'the preview restated its own headline', $text);
};

$tests['a fixture the feed barely covered says only what it knows'] = function () {
    $text = (new Preview())->text([
        'home_name' => 'Kentucky',
        'away_name' => 'Alabama',
        'kickoff' => new DateTimeImmutable('2026-09-12T19:30:00+00:00'),
    ]);

    ok(str_contains($text, 'Alabama at Kentucky'), 'the headline is the one thing that must always be there', $text);
    ok(!str_contains($text, 'No. '), 'an unranked fixture printed a rank anyway', $text);
    ok(!str_contains($text, ' at Kroger'), 'a venue appeared from nowhere', $text);
    ok(!str_contains($text, 'On .'), 'an absent channel was announced as a channel', $text);
    ok(!str_contains($text, ' are , '), 'an absent record was printed as an empty one', $text);
    ok(!str_contains($text, ' game.'), 'a conference was invented', $text);
    /*
     * Three blocks and no more: the headline, when it starts, and the sign-off.
     * Every other paragraph in this preview is earned by something the feed
     * sent, and a fourth here would mean one of them had been printed empty.
     */
    same(3, substr_count($text, "

") + 1, 'a bare fixture produced a block it had nothing to put in');
};

$tests['a relative time never reaches the post'] = function () {
    $text = (new Preview())->text(fixture());

    foreach (['from now', 'ago', 'in 2 hours', 'hours from now'] as $phrase) {
        ok(!str_contains($text, $phrase), 'the preview said "' . $phrase . '", which stops being true', $text);
    }
};

$tests['the time is printed in the zone it was asked for, and says which'] = function () {
    /*
     * 🚨 The default is EASTERN, not UTC, and that is the assertion worth
     * having. UTC was the first answer and it is true and useless — "kickoff is
     * 11:15pm UTC" on a college football board is a number every reader has to
     * convert, which is barely an improvement on the relative time this whole
     * class replaced. The board's own sport decides the floor.
     */
    $default = (new Preview())->text(fixture());
    ok(str_contains($default, 'Kickoff is 3:30pm EDT on'), 'the default zone was not Eastern', $default);

    // And an operator elsewhere is honoured, zone name and all.
    $utc = (new Preview(Recap::EMPHASIS_NONE, new Gridiron(), 'UTC'))->text(fixture());
    ok(str_contains($utc, 'Kickoff is 7:30pm UTC on'), 'an explicit zone was ignored', $utc);

    $london = (new Preview(Recap::EMPHASIS_NONE, new Gridiron(), 'Europe/London'))->text(fixture());
    ok(str_contains($london, '8:30pm BST'), 'a third zone was not converted', $london);

    /*
     * 🚨 The time NEVER appears without a zone on it. That is the one thing a
     * post nobody rewrites cannot get away with: a bare "7:30pm" is a wrong
     * number for everybody who does not live beside the server.
     */
    foreach ([$default, $utc, $london] as $text) {
        ok(preg_match('/\d:\d\d[ap]m [A-Z]{2,5}/', $text) === 1, 'a kickoff time was printed with no zone', $text);
    }
};

$tests['a conference takes the article it is spoken with'] = function () {
    $cases = [
        'SEC' => 'an SEC game',
        'ACC' => 'an ACC game',
        'Big Ten' => 'a Big Ten game',
        'American Athletic' => 'an American Athletic game',
        'Mountain West' => 'a Mountain West game',
        'C-USA' => 'a C-USA game',
    ];

    foreach ($cases as $conference => $expected) {
        $text = (new Preview())->text(fixture([
            'home_conference' => $conference,
            'away_conference' => $conference,
        ]));

        ok(str_contains($text, $expected), $conference . ' did not read as "' . $expected . '"', $text);
    }
};

$tests['each sport calls the start of play by its own name'] = function () {
    $expected = [
        'gridiron' => 'Kickoff is',
        'soccer' => 'Kick-off is',
        'hardwood' => 'Tip-off is',
        'diamond' => 'First pitch is',
        'ice' => 'Puck drop is',
    ];

    $sports = new Sports();

    foreach ($expected as $key => $phrase) {
        $text = (new Preview(Recap::EMPHASIS_NONE, $sports->get($key)))->text(fixture());

        ok(str_contains($text, $phrase), $key . ' did not say "' . $phrase . '"', $text);
    }
};

$tests['an unbeaten record is read from the losses, not guessed'] = function () {
    $both = (new Preview())->text(fixture(['home_record' => '3-0-1', 'away_record' => '2-0']));
    ok(str_contains($both, 'Both come in unbeaten'), 'a draw was counted as a defeat', $both);

    $one = (new Preview())->text(fixture(['home_record' => '1-1']));
    ok(!str_contains($one, 'unbeaten'), 'a side with a loss was called unbeaten', $one);
    ok(str_contains($one, 'Alabama are 1-0, Kentucky 1-1.'), 'the plain form line was not written', $one);

    $none = (new Preview())->text(fixture(['home_record' => '0-0', 'away_record' => '0-0']));
    ok(!str_contains($none, 'unbeaten'), 'two teams who have not played were called unbeaten', $none);
};

$tests['a rank in prose is never written with a hash'] = function () {
    /*
     * 🚨 A post goes through the board's FORMATTER, and "#12" is a token there.
     * Cross-references and Flarum's own Mentions both read `#id` as a link to
     * the discussion with that id — so "Louisiana Tech at #8 LSU" rendered as
     * "Louisiana Tech at Down goes #5 Ole Miss LSU", another thread's title
     * sitting inside the team's name. It shipped to eighty-six posts on a live
     * board before anybody read one closely.
     *
     * "No. 12" is inert whatever is parsing it, and it is what AP style writes
     * in prose. The scoreboard keeps the short form; nothing parses that.
     */
    foreach ([fixture(), fixture(['home_rank' => 3, 'away_rank' => 1]), fixture(['neutral_site' => true])] as $game) {
        $text = (new Preview(Recap::EMPHASIS_MARKDOWN))->text($game);

        ok(! str_contains($text, '#'), 'a hash reached the post text, where a formatter will eat it', $text);
        ok(str_contains($text, 'No. '), 'the rank went missing entirely', $text);
    }
};

$tests['a neutral site is vs, not at'] = function () {
    $text = (new Preview())->text(fixture(['neutral_site' => true]));

    ok(str_contains($text, 'No. 12 Alabama vs Kentucky'), 'a neutral-site game was described as a home game', $text);
};

/* ------------------------------------------------------------------ the runner */

foreach ($tests as $name => $test) {
    $before = count($failures);
    $test();

    if (count($failures) === $before) {
        $passed++;
        echo "  ok   " . $name . "\n";
        continue;
    }

    echo "  FAIL " . $name . "\n";

    foreach (array_slice($failures, $before) as $failure) {
        echo "       " . $failure . "\n";
    }
}

echo "\n" . $passed . '/' . count($tests) . " passed\n";

exit($failures === [] ? 0 : 1);
