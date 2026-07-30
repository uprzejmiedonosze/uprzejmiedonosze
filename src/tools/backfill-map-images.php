<?php
require_once(__DIR__ . '/../inc/include.php');

if (php_sapi_name() !== 'cli') {
    die("This script must be run from CLI.\n");
}

if (!\storage\isEnabled()) {
    die("B2 storage is not enabled in this environment — nothing to backfill.\n");
}

$db = \store\store();

echo "Szukam zgłoszeń z adresem (address.lat)...\n";

$stmt = $db->query("SELECT key FROM applications");
$appIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

$checked = 0;
$missing = 0;
$fixed = 0;
$failed = 0;

foreach ($appIds as $appId) {
    try {
        $app = \app\get($appId);
    } catch (\Exception $e) {
        continue;
    }

    if (!isset($app->address->lat)) continue;

    $mapKey = $app->getMapImageKey();
    if ($mapKey === null) continue;

    $checked++;
    if (\storage\b2()->exists($mapKey)) continue;

    $missing++;
    echo "Brak mapy w B2 dla {$app->getNumber()} ($appId): $mapKey\n";

    // regenerateMapImage() takes its own "syncToS3:$appId" lock internally.
    $newKey = $app->regenerateMapImage();
    if ($newKey !== null) {
        $fixed++;
        echo " -> Zregenerowano: $newKey\n";
    } else {
        $failed++;
        echo " -> Nie udało się zregenerować (Mapbox niedostępny lub brak lngLat).\n";
    }
}

echo "Zakończono. Sprawdzono $checked zgłoszeń z adresem, brakujących map: $missing, naprawiono: $fixed, nieudanych: $failed.\n";
