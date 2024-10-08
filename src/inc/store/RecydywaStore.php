<?PHP namespace recydywa;

CONST TABLE = 'recydywa';

/**
 * Returns the number of applications per specified $plate.
 */
function get(string $plate): Recydywa {
    $cached = \cache\get($plate);
    if ($cached)
        return $cached;
    
    $recydywaJson = \store\get(TABLE, "$plate v2");
    if($recydywaJson)
        return new Recydywa($recydywaJson);

    return update($plate);
}

/**
 * Recalculates recydywa.
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function update(string $plate): Recydywa {
    global $storage;

    $apps = $storage->getApplicationsByPlate($plate);
    $recydywa = Recydywa::withApps($apps);

    \cache\set("%HOST%-$plate", $recydywa);
    \store\set(TABLE, "$plate v2", json_encode($recydywa));
    return $recydywa;
}
