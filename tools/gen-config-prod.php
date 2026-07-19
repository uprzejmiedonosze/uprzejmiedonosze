#!/usr/bin/env php
<?php
// Generates export/config.prod.php from services/.env.prod.
// Usage: php tools/gen-config-prod.php [env-file] [output-file]
//   env-file    default: services/.env.prod
//   output-file default: export/config.prod.php

$envFile    = $argv[1] ?? 'services/.env.prod';
$outputFile = $argv[2] ?? 'export/config.prod.php';

// Strips one layer of matching quotes, same as a shell/dotenv would: a
// double-quoted value decodes \n escapes (needed for PEM keys, which must be
// stored on one line — see OAUTH_PRIVATE_KEY); a single-quoted value is kept
// literal; unquoted values pass through unchanged.
function dotenvValue(string $v): string {
    if (strlen($v) >= 2 && $v[0] === '"' && $v[-1] === '"') {
        return str_replace('\n', "\n", substr($v, 1, -1));
    }
    if (strlen($v) >= 2 && $v[0] === "'" && $v[-1] === "'") {
        return substr($v, 1, -1);
    }
    return $v;
}

$defines = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = dotenvValue(trim($v));
    // Host and scheme are baked into config.env.php from the Docker build args
    // (per deploy target: prod/shadow/staging). Re-exporting the .env.prod
    // values here would override them via getenv() in config.php, making a
    // shadow deploy identify as production (and blanking the asset hashes).
    if ($k === 'APP_HOST' || $k === 'APP_HTTPS') continue;
    if (str_starts_with($k, 'APP_')) {
        $defines[] = 'putenv(' . var_export("$k=$v", true) . ');';
        continue;
    }
    $defines[] = 'define(' . var_export($k, true) . ', ' . var_export($v, true) . ');';
}

file_put_contents($outputFile, "<?php\n" . implode("\n", $defines) . "\n");
