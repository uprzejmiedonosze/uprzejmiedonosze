<?php

require_once(__DIR__ . '/../inc/include.php');
require_once(__DIR__ . '/../inc/integrations/curl.php');
require_once(__DIR__ . '/../inc/store/GlobalStats.php');

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

echo "--- Statystyki: Start odświeżania --- " . date('Y-m-d H:i:s') . "\n";

$tasks = [
    'mainPage'        => fn() => \global_stats\mainPage(useCache: false),
    'appsByDay'       => fn() => \global_stats\appsByDay(useCache: false),
    'statsByDay'      => fn() => \global_stats\statsByDay(useCache: false),
    'statsByYear'     => fn() => \global_stats\statsByYear(useCache: false),
    'statsByCarBrand' => fn() => \global_stats\statsByCarBrand(useCache: false),
];

foreach ($tasks as $name => $task) {
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
