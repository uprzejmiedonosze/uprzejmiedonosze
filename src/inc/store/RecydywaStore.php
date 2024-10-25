<?PHP namespace recydywa;
require(__DIR__ . '/../dataclasses/Recydywa.php');

use cache\Type;

const TABLE = 'recydywa';

/**
 * Returns the number of applications per specified $plate.
 */
function get(string $plateId, bool $withCache=true): Recydywa {
    $cleanPlateId = \recydywa\cleanPlateId($plateId);
    $cached = \cache\get(Type::Recydywa, $cleanPlateId);
    if ($cached && $withCache)
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

    \cache\set(type:Type::Recydywa, key:$cleanPlateId, value:$recydywa, flag:0, expire:0);
    \store\set(TABLE, "$cleanPlateId v2", json_encode($recydywa));
    return $recydywa;
}

function delete(string $plateId) {
    $cleanPlateId = cleanPlateId($plateId);
    \cache\delete(Type::Recydywa, $cleanPlateId);
    \store\delete(TABLE, "$cleanPlateId v2");
}


function cleanPlateId(string $plateId): string {
    return preg_replace('/\s+/', '', trim(strtoupper($plateId)));
}