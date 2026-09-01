<?php
require_once(__DIR__ . '/../inc/include.php');
require_once(__DIR__ . '/../inc/API.php');

if (php_sapi_name() !== 'cli') {
    die("This script must be run from CLI.\n");
}

if (!\storage\isEnabled()) {
    die("B2 storage is not enabled in this environment — nothing to backfill.\n");
}

$dryRun = in_array('--dry-run', $argv, true);

$db = \store\store();

echo "Szukam zgłoszeń z miniaturami (,t) nieobecnymi w CDN...\n";

$stmt = $db->query("SELECT key FROM applications");
$appIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

$checked = 0;
$missing = 0;
$noSource = 0;
$fixed = 0;
$failed = 0;

foreach ($appIds as $appId) {
    try {
        $app = \app\get($appId);
    } catch (\Exception $e) {
        continue;
    }

    foreach (['carImage', 'contextImage', 'thirdImage'] as $imgType) {
        $url = $app->$imgType->url ?? null;
        if ($url === null || !preg_match('/\.jpg$/', $url)) continue;

        $thumbKey = preg_replace('/\.jpg$/', ',t.jpg', $url);

        $checked++;
        if (\storage\exists($thumbKey)) continue;

        $missing++;
        echo "Brak miniatury w CDN: $thumbKey\n";

        if (!file_exists(ROOT . $url) && !\storage\exists($url)) {
            $noSource++;
            echo " -> brak źródła (pełna wersja nie istnieje ani lokalnie, ani w B2).\n";
            continue;
        }

        if ($dryRun) {
            echo " -> (dry-run) do zregenerowania z $url\n";
            continue;
        }

        try {
            $needFull = !file_exists(ROOT . $url);
            \storage\ensure_local($url);

            $thumbPath = ROOT . $thumbKey;
            if (!file_exists($thumbPath)) {
                $dir = dirname($thumbPath);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                imagejpeg(resize_image(ROOT . $url, 600, 600, false), $thumbPath);
            }

            if (\storage\upload($thumbPath, $thumbKey)) {
                $fixed++;
                echo " -> Zregenerowano: $thumbKey\n";
            } else {
                $failed++;
                echo " -> Nie udało się wgrać do B2: $thumbKey\n";
            }

            @unlink($thumbPath);
            if ($needFull) @unlink(ROOT . $url);
        } catch (\Throwable $e) {
            $failed++;
            echo " -> Błąd: " . $e->getMessage() . "\n";
        }
    }
}

echo "Zakończono. Sprawdzono $checked miniatur, brakujących: $missing" .
     ($dryRun ? "" : ", naprawiono: $fixed, nieudanych: $failed") .
     ", bez źródła: $noSource.\n";