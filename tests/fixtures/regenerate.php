<?php

declare(strict_types=1);

/*
 * Rebuilds `normalised-box-score.json` from the raw provider answer.
 *
 * 🚨 The normalised fixture is NOT hand-written — it is what Picks' own
 * `BoxScoreService::normalise()` produces from `cfbd-box-score.json`, which is
 * CollegeFootballData's real answer for Notre Dame 41, Wisconsin 13. A fixture
 * somebody typed proves the recap agrees with whoever typed it; this one proves
 * it agrees with the shape Picks actually stores.
 *
 * Run it whenever the normaliser changes:
 *
 *     php tests/fixtures/regenerate.php
 *
 * It needs the Picks extension checked out beside this one, which is why it is
 * a separate script and not part of `tests/run.php` — the test suite must run
 * with nothing but this repository.
 */

$picks = getenv('PICKS_PATH') ?: dirname(__DIR__, 3) . '/picks';

if (!is_file($picks . '/src/Service/BoxScoreService.php')) {
    fwrite(STDERR, "Picks not found at {$picks}. Set PICKS_PATH.\n");
    exit(1);
}

require $picks . '/src/Service/BoxScoreService.php';

require $picks . '/src/Service/Leagues/League.php';
require $picks . '/src/Service/Leagues/Leagues.php';
require $picks . '/src/Service/Providers/Provider.php';
require $picks . '/src/Service/Providers/EspnProvider.php';

$raw = json_decode((string) file_get_contents(__DIR__ . '/cfbd-box-score.json'), true);

/*
 * `normalise()` is pure — it reads nothing off the service — so the constructor
 * dependencies are never touched. Reflection avoids dragging Flarum's container
 * into a fixture script.
 */
$service = (new ReflectionClass(Resofire\Picks\Service\BoxScoreService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($service, 'normalise');

$document = $method->invoke($service, (int) $raw['game'], $raw['teams'], $raw['players']);

if ($document === null) {
    fwrite(STDERR, "The normaliser rejected the fixture.\n");
    exit(1);
}

file_put_contents(
    __DIR__ . '/normalised-box-score.json',
    json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

echo "Wrote normalised-box-score.json\n";

/*
 * 🚨 And the same for every sport ESPN answers, through the SAME normaliser and
 * the same adapter Picks uses in production. A fixture built any other way
 * would prove the recap agrees with a payload written to suit it; these prove
 * it agrees with what a Sunday actually delivers.
 */
$leagues = new Resofire\Picks\Service\Leagues\Leagues();

$espn = new class extends Resofire\Picks\Service\Providers\EspnProvider {
    public string $file = '';

    public function __construct()
    {
    }

    protected function get(string $path, array $params): array
    {
        return json_decode((string) file_get_contents($this->file), true);
    }
};

foreach (['nfl' => 'nfl', 'nba' => 'nba', 'mlb' => 'mlb', 'nhl' => 'nhl', 'soccer' => 'epl'] as $file => $league) {
    $espn->file = $picks . '/tests/fixtures/espn-summary-' . $file . '.json';

    /*
     * 🚨 A DISTINCT event id per sport. The adapter caches a summary by event
     * id — deliberately, so a game is fetched once per run — and reusing one id
     * here handed back the first sport's box score five times. Every fixture
     * was American football, and every recap read plausibly.
     */
    $sides = $espn->boxScore($leagues->get($league), 'fixture-' . $file, 2026, 1);

    if ($sides === null) {
        fwrite(STDERR, "The adapter rejected {$file}.\n");
        exit(1);
    }

    $document = $method->invoke($service, 0, $sides['teams'], $sides['players'], $leagues->get($league));

    file_put_contents(
        __DIR__ . '/espn-' . $file . '.json',
        json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    echo "Wrote espn-{$file}.json\n";
}
