#!/usr/bin/env php
<?php
// Generates export/config.prod.php from services/.env.prod.
// Usage: php tools/gen-config-prod.php [env-file] [output-file]
//   env-file    default: services/.env.prod
//   output-file default: export/config.prod.php

$envFile    = $argv[1] ?? 'services/.env.prod';
$outputFile = $argv[2] ?? 'export/config.prod.php';

$defines = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);
    if (str_starts_with($k, 'APP_')) {
        $defines[] = 'putenv(' . var_export("$k=$v", true) . ');';
        continue;
    }
    $defines[] = 'define(' . var_export($k, true) . ', ' . var_export($v, true) . ');';
}

file_put_contents($outputFile, "<?php\n" . implode("\n", $defines) . "\n");
