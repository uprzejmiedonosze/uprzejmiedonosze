<?PHP namespace recydywa;
require(__DIR__ . '/../dataclasses/Recydywa.php');

const TABLE = 'recydywa';

/**
 * Returns the number of applications per specified $plate.
 */
function get(string $plateId): Recydywa {
    $cleanPlateId = \recydywa\cleanPlateId($plateId);
    $cached = \cache\get("recydywa-$cleanPlateId");
    if ($cached)
        return $cached;
    
    $recydywaJson = \store\get(TABLE, "$cleanPlateId v2");
    if($recydywaJson)
        return new Recydywa($recydywaJson);

    return update($cleanPlateId);
}

/**
 * Recalculates recydywa.
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function update(string $plateId): Recydywa {
    $cleanPlateId = cleanPlateId($plateId);
    $apps = \app\byPlate($cleanPlateId);
    $recydywa = Recydywa::withApps($apps);

    \cache\set("recydywa-$cleanPlateId", $recydywa);
    \store\set(TABLE, "$cleanPlateId v2", json_encode($recydywa));
    return $recydywa;
}

function cleanPlateId(string $plateId): string {
    return preg_replace('/\s+/', '', trim(strtoupper($plateId)));
}