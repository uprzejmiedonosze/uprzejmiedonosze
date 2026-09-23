<?PHP namespace admin;

// Lokalna iteracja nad algorytmem wizji (faza 3 z planu w uprzejmiedonosze-pro:
// nie-jestem-zadowolony-z-curious-comet.md). Woła \vision\analyzeCandidate() BEZPOŚREDNIO,
// bez Slima/HTTP/auth — dokładnie tę samą funkcję, którą produkcja woła z
// POST /api/rest/vision/candidate (src/api/rest/index.php). Zero drugiej implementacji
// promptu/ALPR/pipeline'u do trzymania w synchronizacji z produkcją.
//
// Wejście: manifest.json produkowany przez ../uprzejmiedonosze-pro/tests/eval/prep.ts (faza 2,
// za darmo: resize/EXIF/grupowanie — kod appki, src/lib/grouping.ts, bez zmian).
//
// Celowo require'uje src/inc/... (nie export/inc/...) — edycja Vision.php/VisionSchema.php
// działa od razu przy kolejnym uruchomieniu, bez ręcznego kopiowania do export/ (to drugie
// trzeba tylko przed `make test`).
//
// Użycie:
//   php src/tools/vision-local.php
//   php src/tools/vision-local.php --candidate R001,R004
//   php src/tools/vision-local.php --manifest /inna/sciezka/manifest.json --out /tmp/wyniki

date_default_timezone_set('Europe/Warsaw');

$ROOT = dirname(__DIR__, 2); // src/tools -> src -> repo root
require($ROOT . '/vendor/autoload.php');
require($ROOT . '/config.php'); // sekrety NAJPIERW (OPENAI_API_KEY, OPENAI_PROJECT, PLATERECOGNIZER_SECRET) — plain define()y, src/inc/config.php ma !defined() guardy
require($ROOT . '/src/inc/include.php'); // config (reszta stałych) + logger + cache + telemetry
require($ROOT . '/src/inc/integrations/Vision.php'); // require'uje sam VisionSchema.php + data.php + plateRecognizer.php

// --- argumenty CLI ---

$defaultManifest = $ROOT . '/../uprzejmiedonosze-pro/tests/.cache/prep/manifest.json';
$args = ['manifest' => $defaultManifest, 'photos-dir' => null, 'out' => $ROOT . '/../uprzejmiedonosze-pro/tests/results/local', 'candidate' => null, 'user' => 'szymon@nieradka.net'];
for ($i = 1; $i < count($argv); $i++) {
    if (str_starts_with($argv[$i], '--') && isset($argv[$i + 1])) {
        $args[substr($argv[$i], 2)] = $argv[++$i];
    }
}
$manifestPath = $args['manifest'];
$photosDir = $args['photos-dir'] ?? dirname($manifestPath);
$outDir = $args['out'];
$onlyCandidates = $args['candidate'] ? array_map('trim', explode(',', $args['candidate'])) : null;
$userEmail = $args['user'];

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Brak manifestu: $manifestPath (odpal najpierw: cd uprzejmiedonosze-pro/tests/eval && npx tsx prep.ts)\n");
    exit(1);
}
@mkdir($outDir, 0777, true);

$manifest = json_decode(file_get_contents($manifestPath), true);
if (!$manifest) {
    fwrite(STDERR, "Zły JSON w $manifestPath\n");
    exit(1);
}

$photoMetaById = [];
foreach ($manifest['photos'] as $p) $photoMetaById[$p['photoId']] = $p;

echo "vision-local: model=" . OPENAI_VISION_MODEL . " detail=" . OPENAI_VISION_DETAIL . " manifest=$manifestPath\n\n";

$totalCostUsd = 0.0;
foreach ($manifest['candidates'] as $cand) {
    if ($onlyCandidates && !in_array($cand['reportId'], $onlyCandidates, true)) continue;

    $photos = [];
    foreach ($cand['photoIds'] as $i => $photoId) {
        $meta = $photoMetaById[$photoId] ?? null;
        if (!$meta) {
            fwrite(STDERR, "  ! brak metadanych dla $photoId w manifeście, pomijam kandydata {$cand['reportId']}\n");
            continue 2;
        }
        $path = $photosDir . '/' . $meta['thumbFile'];
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            fwrite(STDERR, "  ! brak pliku miniatury $path, pomijam kandydata {$cand['reportId']}\n");
            continue 2;
        }
        $photos[] = ['photoId' => $photoId, 'photo_index' => $i, 'bytes' => $bytes];
    }
    if (!$photos) continue;

    echo "=== {$cand['reportId']} (" . count($photos) . " zdjęć) ===\n";
    $t0 = microtime(true);
    try {
        $result = \vision\analyzeCandidate($photos, $userEmail, $cand['reportId']);
    } catch (\Throwable $e) {
        printf("  BŁĄD: %s: %s\n\n", get_class($e), $e->getMessage());
        continue;
    }
    $dt = microtime(true) - $t0;

    foreach ($result['photos'] as $p) {
        $plate = $p['plate'] ?? [];
        $plateStr = ($plate['readable'] ?? false) ? ($plate['text'] ?? '?') : '—';
        $verified = ($p['plate_verified'] ?? false) ? '✓' : (($plate['plate_check'] ?? null) === 'unverified-box' ? '?' : ' ');
        printf(
            "  [%d] %-9s q=%.2f plate=%-9s%s bbox_car=%s bbox_plate=%s markers=%s kat=%s\n",
            $p['photo_index'],
            $p['role'],
            $p['quality'] ?? 0,
            $plateStr,
            $verified,
            json_encode($p['car']['bbox'] ?? null),
            json_encode($plate['bbox'] ?? null),
            implode(',', $p['markers'] ?? []),
            $p['suggested_category'] ?? '?',
        );
        if (!empty($p['plate_debug'])) {
            $d = $p['plate_debug'];
            $modelText = $d['model']['text'] ?? '—';
            $alprText = $d['alpr']['text'] ?? '—';
            $mark = $modelText !== $alprText ? ' <-- RÓŻNIĄ SIĘ' : '';
            printf("       plate_debug: model=%-9s alpr=%-9s%s\n", $modelText, $alprText, $mark);
        }
    }
    $usage = $result['usage'];
    printf(
        "  usage: %d wywołań, %d+%d tokenów, koszt \$%.5f, %.1fs\n",
        $usage['calls'], $usage['prompt_tokens'], $usage['completion_tokens'], $usage['cost_usd'], $dt,
    );
    foreach ($result['warnings'] ?? [] as $w) echo "  ! $w\n";
    $totalCostUsd += $usage['cost_usd'];

    file_put_contents(
        $outDir . '/' . $cand['reportId'] . '.json',
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    );
    echo "\n";
}

printf("Razem: \$%.5f. Wyniki zapisane w %s\n", $totalCostUsd, $outDir);
