<?php
/**
 * Ręczna ewaluacja OCR/ekstrakcji pism na prawdziwych skanach (NIE w CI – woła płatne API OpenAI).
 *
 *   OPENAI_API_KEY=… php tools/letter_eval.php <katalog-ze-skanami> [model] [--only=fragment-nazwy]
 *
 * Katalog ma zawierać skany (X.jpg / X.png) i expected.json (klucz = nazwa skanu bez rozszerzenia), np.
 * ../uprzejmiedonosze-pro/tests/correspondence. Dla każdego skanu porównuje: kind, liczbę spraw oraz na każdą oczekiwaną
 * sprawę – tablicę, datę, outcome i numery spraw (ids). Wynik = tabela i podsumowanie; kod wyjścia 0.
 */

require_once __DIR__ . '/../src/inc/include.php';
require_once __DIR__ . '/../src/inc/integrations/LetterVision.php';

$dir = rtrim($argv[1] ?? '', '/');
if ($dir === '' || !is_file("$dir/expected.json")) {
    fwrite(STDERR, "Użycie: php tools/letter_eval.php <katalog-ze-skanami> [model] [--only=fragment]\n");
    exit(2);
}
$model = null;
$only = null;
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--only=')) $only = substr($arg, 7);
    else $model = $arg;
}
$model ??= OPENAI_LETTER_MODEL;
$expected = json_decode(file_get_contents("$dir/expected.json"), true);

$totals = ['files' => 0, 'kind' => 0, 'cases' => 0, 'plate' => 0, 'date' => 0, 'outcome' => 0, 'ids' => 0, 'expectedCases' => 0, 'cost' => 0.0];

foreach ($expected as $name => $exp) {
    if ($only !== null && !str_contains($name, $only)) continue;
    $path = null;
    foreach (['jpg', 'jpeg', 'png'] as $ext) if (is_file("$dir/$name.$ext")) $path = "$dir/$name.$ext";
    if ($path === null) { echo "SKIP  $name (brak skanu)\n"; continue; }

    $bytes = file_get_contents($path);
    if (str_ends_with($path, '.png')) $bytes = \vision\normalizePngToJpeg($bytes);

    $totals['files']++;
    try {
        $r = \letter\analyzeLetter([$bytes], $model);
    } catch (\Throwable $e) {
        echo "FAIL  $name: " . $e->getMessage() . "\n";
        continue;
    }
    $totals['cost'] += $r['usage']['cost_usd'];
    $kindOk = $r['letter']['kind'] === $exp['kind'];
    $countOk = count($r['cases']) === count($exp['cases']);
    $totals['kind'] += (int)$kindOk;
    $totals['cases'] += (int)$countOk;
    printf("%s  %s  kind=%s(%s) cases=%d/%d  $%.4f\n", $kindOk && $countOk ? 'OK  ' : 'DIFF', $name, $r['letter']['kind'], $exp['kind'],
        count($r['cases']), count($exp['cases']), $r['usage']['cost_usd']);

    $used = [];
    foreach ($exp['cases'] as $i => $e) {
        $totals['expectedCases']++;
        // najlepsze dopasowanie: ta sama tablica albo ten sam numer sprawy, w ostateczności ta sama pozycja
        $best = null;
        foreach ($r['cases'] as $j => $a) {
            if (isset($used[$j])) continue;
            $same = ($e['plate'] ?? null) !== null && $a['plate'] === $e['plate'] && ($a['date'] ?? null) === ($e['date'] ?? null);
            foreach ($e['ids'] ?? [] as $k => $v) if (($a['ids'][$k] ?? null) === $v) $same = true;
            if ($same) { $best = $j; break; }
        }
        $best ??= isset($r['cases'][$i]) && !isset($used[$i]) ? $i : null;
        if ($best === null) { echo "      case $i: BRAK odpowiednika\n"; continue; }
        $used[$best] = true;
        $a = $r['cases'][$best];
        $plateOk = ($a['plate'] ?? null) === ($e['plate'] ?? null);
        $dateOk = ($a['date'] ?? null) === ($e['date'] ?? null);
        $outOk = $a['outcome'] === $e['outcome'];
        $idsOk = true;
        foreach ($e['ids'] ?? [] as $k => $v) if (($a['ids'][$k] ?? null) !== $v) $idsOk = false;
        $totals['plate'] += (int)$plateOk;
        $totals['date'] += (int)$dateOk;
        $totals['outcome'] += (int)$outOk;
        $totals['ids'] += (int)$idsOk;
        if (!($plateOk && $dateOk && $outOk && $idsOk)) {
            printf("      case %d: plate %s%s date %s%s outcome %s%s ids %s\n", $i,
                $plateOk ? 'ok' : "{$a['plate']}≠{$e['plate']}", '', $dateOk ? 'ok' : "{$a['date']}≠" . ($e['date'] ?? 'null'), '',
                $outOk ? 'ok' : "{$a['outcome']}≠{$e['outcome']}", '', $idsOk ? 'ok' : json_encode($a['ids'], JSON_UNESCAPED_UNICODE));
        }
    }
    foreach ($r['warnings'] as $w) echo "      ! $w\n";
}

$n = max(1, $totals['expectedCases']);
printf("\nmodel=%s  skanów=%d  kind %d/%d  liczba spraw %d/%d\n", $model, $totals['files'], $totals['kind'], $totals['files'], $totals['cases'], $totals['files']);
printf("sprawy: plate %d/%d  date %d/%d  outcome %d/%d  ids %d/%d  koszt $%.4f\n", $totals['plate'], $n, $totals['date'], $n, $totals['outcome'], $n, $totals['ids'], $n, $totals['cost']);
