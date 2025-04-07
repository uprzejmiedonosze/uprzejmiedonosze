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

function getDetailed(string $plateId): array|false {
    $cleanPlateId = cleanPlateId($plateId);
    $sql = <<<SQL
    select json_extract(value, '$.status'),
        json_extract(value, '$.externalId'),
        email,
        json_extract(value, '$.smCity'),
        json_extract(value, '$.stopAgresji'),
        json_extract(value, '$.date')
    from applications
    where plateId = :plateId
        and json_extract(value, '$.status') not in ('archived', 'ready', 'draft', 'confirmed')
    order by json_extract(value, '$.externalId') is null,
        email = :currentUser,
        json_extract(value, '$.date')
    limit 1
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->bindValue(':plateId', $cleanPlateId);
    $stmt->bindValue(':currentUser', \user\currentEmail());
    $stmt->execute();

    $details = $stmt->fetchAll(\PDO::FETCH_FUNC,
        fn($status, $externalId, $email, $smCity, $stopAgresji, $date) =>
            array(
                'status' => $status,
                'externalId' => $externalId,
                'email' => \crypto\encode($email, $_SESSION['user_id'], $plateId),
                'owner' => $email == \user\currentEmail(),
                'smCity' => $smCity,
                'stopAgresji' => $stopAgresji,
                'date' => $date));
    if (!$details)
        return false;

    return $details;
}

function top100(\user\User $whoIsWathing): array {
    $canImageBeShown = function (array &$top100) use ($whoIsWathing) {
        foreach($top100 as $recydywa)
            $recydywa['app']->showImage = $recydywa['app']->canImageBeShown($whoIsWathing, $recydywa['canShareRecydywa']);
    };

    $top100 = \cache\get(Type::RecydywaStats, "top100");
    if($top100) {
        $canImageBeShown($top100);
        return $top100;
    }

    $olderThan = date('Y-m-d\TH:i:s', strtotime("-3 years"));

    $sql = <<<SQL

    with apps as (
        select a.key,
            a.value,
            a.email,
            plateId,
            json_extract(u.value, '$.data.shareRecydywa') as shareRecydywa,
            json_extract(a.value, '$.faces.count') as faces,
            ROW_NUMBER() OVER (
                PARTITION BY plateId
                ORDER BY json_extract(u.value, '$.data.shareRecydywa') DESC,
                    json_extract(a.value, '$.faces.count'),
                    json_extract(a.value, '$.added') DESC
            ) AS "rnk"
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
        value as app,
        email,
        shareRecydywa
    from apps
    group by plateId
    having count(distinct email) > 1
    order by 2 desc, 3 desc
    limit 100;
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

    $canImageBeShown($top100);
    \cache\set(Type::RecydywaStats, "top100", $top100);
    return $top100;
}


function cleanPlateId(string $plateId): string {
    return substr(preg_replace('/\s+/', '', trim(strtoupper($plateId))), 0, 8);
}