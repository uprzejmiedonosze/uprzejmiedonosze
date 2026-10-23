<?php

require_once(__DIR__ . '/../inc/include.php');
require_once(__DIR__ . '/../inc/integrations/curl.php');
require_once(__DIR__ . '/../inc/store/GlobalStats.php');

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// --missing-only (hourly cron): recompute only what's gone from memcached
// (restart, eviction), so the stats come back within an hour instead of at the
// next full run. Requests never compute them, see GlobalStats.php.
$missingOnly = in_array('--missing-only', $argv);

echo "--- Statystyki: Start odświeżania --- " . date('Y-m-d H:i:s') . "\n";

// task => [memcached key it fills, refresh]
$tasks = [
    'mainPage'        => ['mainPage',        fn() => \global_stats\mainPage(useCache: false)],
    'appsByDay'       => ['appsByDay',       fn() => \global_stats\appsByDay(useCache: false)],
    'statsByDay'      => ['statsByDay',      fn() => \global_stats\statsByDay(useCache: false)],
    'statsByYear'     => ['statsByYear2',    fn() => \global_stats\statsByYear(useCache: false)],
    'statsByCarBrand' => ['statsByCarBrand', fn() => \global_stats\statsByCarBrand(useCache: false)],
];

foreach ($tasks as $name => [$cacheKey, $task]) {
    if ($missingOnly && \cache\get(\cache\Type::GlobalStats, $cacheKey) !== false) {
        continue;
    }
    echo "Odświeżam: $name... ";
    $start = microtime(true);
    try {
        $task();
        $end = microtime(true);
        printf("OK (%.2fs)\n", $end - $start);
    } catch (\Exception $e) {
        echo "BŁĄD: " . $e->getMessage() . "\n";
    }
    echo "Oczekiwanie 60s na zwolnienie zasobów dyskowych...\n";
    sleep(60);
}

echo "--- Gotowe --- " . date('Y-m-d H:i:s') . "\n";
