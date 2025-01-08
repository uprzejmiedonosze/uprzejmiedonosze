<?PHP namespace app;

require(__DIR__ . '/../dataclasses/Application.php');
use cache\Type;

const TABLE = 'applications';

function get(string $appId): Application {
    if(!$appId){
        throw new \Exception("Próba pobrania zgłoszenia bez podania numeru");
    }
    @setSentryTag("appId", $appId);

    $stmt = \store\prepare(
        'SELECT value, email FROM ' . TABLE . ' WHERE key = :key;'
    );
    $stmt->bindValue(':key', $appId, \PDO::PARAM_STR);
    $stmt->execute();

    $row = $stmt->fetch(\PDO::FETCH_NUM);
    if(!$row){
        throw new \Exception("Próba pobrania nieistniejącego zgłoszenia '$appId'", 404);
    }
    $application = Application::withJson($row[0], $row[1]);
    return $application;
}

function save(Application $application): Application{
    @setSentryTag("appId", $application->id);
    if($application->status !== 'draft' && $application->status !== 'ready') {
        if(!$application->hasNumber()) {
            $appNumber = nextNumber($application->email);
            $application->seq = $appNumber;
            $application->number = 'UD/' . $application->user->number . '/' . $appNumber;
        }
    }
    \store\set(TABLE, $application->id, $application->encode(), $application->email);

    if ($application->carInfo->plateId ?? false) {
        $cleanPlateId = \recydywa\cleanPlateId($application->carInfo->plateId);
        \cache\delete(type::AppsByPlate, $cleanPlateId);
    }
    return $application;
}

function checkId(string $appId): bool {
    $json = \store\get(TABLE, $appId);
    return is_string($json);
}

function sent(int $daysAgo=31): array {
    $olderThan = date('Y-m-d\TH:i:s', strtotime("-$daysAgo days"));
    $sql = <<<SQL
        select key, value, email
        from applications
        where email = :email
            and json_extract(value, '$.status') in ('confirmed-waiting', 'confirmed-waitingE', 'confirmed-sm')
            and json_extract(value, '$.sent.date') < :olderThan
        order by json_extract(value, '$.seq') desc,
            json_extract(value, '$.added') desc
    SQL;
    $stmt = \store\prepare($sql);
    $stmt->bindValue(':email', \user\currentEmail());
    $stmt->bindValue(':olderThan', $olderThan);
    $stmt->execute();

    $apps = Array();

    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        $apps[$row[0]] = Application::withJson($row[1], $row[2]);
    }

    usort($apps, function ($left, $right) {
        if($left->sent->to > $right->sent->to) return 1;
        if($left->sent->to < $right->sent->to) return -1;
        if($left->sent->date > $right->sent->date) return 1;
        if($left->sent->date < $right->sent->date) return -1;
        if($left->seq > $right->seq) return 1;
        if($left->seq < $right->seq) return -1;
        return 0;
    });

    return $apps;
}

function nextNumber(string $email): int{
    logger("nextNumber $email");
    $sql = <<<SQL
        select max(json_extract(value, '$.seq'))
        from applications
        where email = :email;
    SQL;
    $stmt = \store\prepare($sql);
    $stmt->bindValue(':email', $email);
    $stmt->execute();

    $ret = $stmt->fetch(\PDO::FETCH_NUM);
    if(!isset($ret[0])) {
        return 1;
    }
    $number = intval($ret[0]);
    return $number + 1;
}

function byPlate(string $plateId): array|null {
    $plateId = trim(strtoupper($plateId));
    $cleanPlateId = \recydywa\cleanPlateId($plateId);
    $cache = \cache\get(Type::AppsByPlate, $cleanPlateId);
    if($cache){
        return $cache;
    }

    $plateId = \SQLite3::escapeString($plateId);
    $cleanPlateId = \SQLite3::escapeString($cleanPlateId);

    $sql = <<<SQL
        select value, email
        from applications 
        where json_extract(value, '$.status') not in ('archived', 'ready', 'draft')
        and json_extract(value, '$.carInfo.plateId') in (:plateId, :cleanPlateId);
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->bindValue(':plateId', $plateId);
    $stmt->bindValue(':cleanPlateId', $cleanPlateId);
    $stmt->execute();

    $apps = $stmt->fetchAll(\PDO::FETCH_FUNC,
        fn($json, $email) => Application::withJson($json, $email));

    \cache\set(Type::AppsByPlate, $cleanPlateId, $apps);
    return $apps;
}

function byNumber($number, $apiToken){
    if($apiToken !== API_TOKEN){
        throw new \Exception('Dostęp zabroniony');
    }
    $sql = <<<SQL
        select value, email
        from applications
        where lower(json_extract(value, '$.number')) = lower(:number)
        limit 1;
SQL;

    $stmt = \store\prepare($sql);
    $stmt->bindValue(':number', $number);
    $stmt->execute();

    $row = $stmt->fetch(\PDO::FETCH_NUM);

    return Application::withJson($row[0], $row[1]);
}

function galleryByCity(bool $useCache=true){
    $stats = \cache\get(Type::GlobalStats, "galleryByCity");
    if($useCache && $stats){
        return $stats;
    }

    $sql = <<<SQL
        select json_extract(value, '$.address.city') as city,
            count(key) as cnt
        from applications
        where json_extract(value, '$.addedToGallery.state') is not null
            and  json_extract(value, '$.address.city') != ''
        group by json_extract(value, '$.address.city')
        order by 2 desc
        limit 10
    SQL;

    $stats = \store\query($sql)->fetchAll(\PDO::FETCH_NUM);
    \cache\set(Type::GlobalStats, 'galleryByCity', $stats);
    return $stats;
}