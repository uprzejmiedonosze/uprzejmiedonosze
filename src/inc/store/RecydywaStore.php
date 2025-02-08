<?PHP namespace recydywa;
require(__DIR__ . '/../dataclasses/Recydywa.php');

use app\Application;
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
 */
function update(string $plateId): Recydywa {
    $cleanPlateId = cleanPlateId($plateId);
    $apps = \app\byPlate($cleanPlateId);
    $recydywa = Recydywa::withApps($apps);

    if ($recydywa->usersCnt > 1)
        foreach($apps as $app)
            \queue\produce($app->id);

    set($cleanPlateId, $recydywa);
    return $recydywa;
}

function set(string $plateId, Recydywa $recydywa) {
    $cleanPlateId = cleanPlateId($plateId);
    \cache\set(type:Type::Recydywa, key:$cleanPlateId, value:$recydywa, flag:0, expire:0);
    \store\set(TABLE, "$cleanPlateId v2", json_encode($recydywa));
}

function delete(string $plateId) {
    $cleanPlateId = cleanPlateId($plateId);
    \cache\delete(Type::Recydywa, $cleanPlateId);
    \store\delete(TABLE, "$cleanPlateId v2");
}

function top100(\user\User $whoIsWathing): array {
    $cache = \cache\get(Type::RecydywaStats, "top100");
    if($cache){
        //return $cache;
    }

    $olderThan = date('Y-m-d\TH:i:s', strtotime("-1 years"));

    $sql = <<<SQL

    with apps as (
        select a.key,
            a.value,
            a.email,
            plateId,
            json_extract(u.value, '$.data.shareRecydywa') as shareRecydywa,
            json_extract(a.value, '$.faces.count') as faces
        from applications a
        inner join users u on email = u.key
        where json_extract(a.value, '$.status') not in ('archived', 'ready', 'draft', 'confirmed')
            and json_extract(a.value, '$.date') > :olderThan
            and plateId is not null
            and plateId <> ''
            and plateId <> 'BRAK')
    select plateId,
        count(distinct email) as usersCnt,
        count(key) as appsCnt,
        first_value(value) over (
            partition by plateId
            order by shareRecydywa desc, faces
        ) as app,
        first_value(email) over (
            partition by plateId
            order by shareRecydywa desc, faces
        ) as email,
        first_value(shareRecydywa) over (
            partition by plateId
            order by shareRecydywa desc, faces
        ) as shareRecydywa
    from apps
    group by plateId
    having count(distinct email) > 1
    order by 2 desc, 3 desc
    limit 10;
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->bindValue(':olderThan', $olderThan);
    $stmt->execute();

    $top100 = $stmt->fetchAll(\PDO::FETCH_FUNC,
        fn($plateId, $usersCnt, $appsCnt, $app, $email, $canShareRecydywa) =>
            array(
                'plateId' => $plateId,
                'usersCnt' => $usersCnt,
                'appsCnt' => $appsCnt,
                'canShareRecydywa' => $canShareRecydywa,
                'app' => Application::withJson($app, $email)));

    foreach($top100 as $recydywa) 
        $recydywa['app']->showImage = $recydywa['app']->canImageBeShown($whoIsWathing, $recydywa['canShareRecydywa']);

    \cache\set(Type::RecydywaStats, "top100", $top100);
    return $top100;
}


function cleanPlateId(string $plateId): string {
    return substr(preg_replace('/\s+/', '', trim(strtoupper($plateId))), 0, 8);
}