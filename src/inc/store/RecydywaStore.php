<?PHP namespace recydywa;

use NoSQLite;

const STORE = __DIR__ . '/../../db/store.sqlite';
$store = (new NoSQLite(STORE))->getStore('recydywa');

/**
 * Returns the number of applications per specified $plate.
 */
function get(string $plate): Recydywa {
    global $store;

    $cached = \cache\get($plate);
    if ($cached)
        return $cached;
    
    $recydywaJson = $store->get("$plate v2");
    if($recydywaJson)
        return new Recydywa($recydywaJson);

    return update($plate);
}

/**
 * Recalculates recydywa.
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function update(string $plate): Recydywa {
    global $store, $storage;

    $apps = $storage->getApplicationsByPlate($plate);
    $recydywa = Recydywa::withApps($apps);

    \cache\set("%HOST%-$plate", $recydywa);
    $store->set("$plate v2", json_encode($recydywa));
    return $recydywa;
}
