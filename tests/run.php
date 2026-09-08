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
require __DIR__ . '/../src/Service/Sports/Sports.php';
require __DIR__ . '/../src/Service/Recap.php';

use ErnestDefoe\Gameday\Service\Recap;
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
