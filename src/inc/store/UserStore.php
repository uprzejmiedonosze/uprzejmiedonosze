<?PHP namespace user;

require(__DIR__ . '/../dataclasses/User.php');

use app\Application;

CONST TABLE = 'users';
$currentUser = null;

current();

// getUser
function get(string $email): User {
    $json = \store\get(TABLE, $email);
    if(!$json){
        throw new \Exception("Próba pobrania nieistniejącego użytkownika '$email'", 404);
    }
    $user = new User($json);
    setSentryTag("userNumber", $user->getNumber() ?? 0);
    return $user;
}

function canShareRecydywa(string $email): bool {
    return get($email)->shareRecydywa();
}

// saveUser
function save(User $user): void {
    if(!isset($user->number)){
        $user->number = nextNumber();
    }
    \store\set(TABLE, $user->getEmail(), json_encode($user));
}

// getCurrentUser
function current(): User{
    global $currentUser;
    if(is_null($currentUser)){
        try{
            $currentUser = get(currentEmail());
        }catch(\Exception $e){
            $currentUser = new User();
        }
    }
    return $currentUser;
}

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function currentEmail(): string{
    if(!empty($_SESSION['user_email'])){
        return $_SESSION['user_email'];
    }
    throw new \Exception("Próba pobrania danych niezalogowanego użytkownika");
}

function apps(User $user, string $status = 'all', string $search = 'all', int $limit = 0, int $offset = 0): array {
    $userEmail = $user->getEmail();

    $params = [':email' => $userEmail];
    
    $limitOffset = '';
    if ($limit > 0) {
        $params += [':limit' => $limit];
        $params += [':offset' => $offset];
        $limitOffset = <<<SQL
            limit :limit offset :offset
        SQL;
    }

    $whereStatus = <<<SQL
        and json_extract(value, '$.status') not in ('ready', 'draft')
    SQL;
    if ($status !== 'all') {
        $whereStatus = <<<SQL
            and json_extract(value, '$.status') = :status
        SQL;
        $params += [':status' => $status];
    }

    $whereSearch = '';
    if ($search !== 'all') {
        $whereSearch = <<<SQL
            and lower(value) like lower(:search)
        SQL;
        $params += [':search' => "%$search%"];
    }

    $sql = <<<SQL
        select value
        from applications
        where email = :email
            $whereStatus
            $whereSearch
        order by json_extract(value, '$.seq') desc,
            json_extract(value, '$.added') desc
        $limitOffset
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(\PDO::FETCH_FUNC,
        fn($value) => \app\Application::withJson($value));
}


function nextNumber(): int{
    $sql = <<<SQL
        select max(json_extract(value, '$.number'))
        from users;
    SQL;
    $stmt = \store\prepare($sql);
    $stmt->execute();

    $ret = $stmt->fetch(\PDO::FETCH_NUM);
    if(count($ret) == 0)
        return 1;

    $number = intval($ret[0]);
    logger("nextUserNumber $number + 1");
    return $number + 1;
}

function points(User $user): Array{
    $userEmail = $user->getEmail();
    $email = \SQLite3::escapeString($userEmail);

    $sql = <<<SQL
        select
            cast(json_extract(value, '$.category') as integer) as category,
            count(key) as cnt
        from applications
        where email = :email
            and json_extract(value, '$.status') in ('confirmed-fined')
        group by 1
        order by 1;
    SQL;
    $stmt = \store\prepare($sql);
    $stmt->bindValue(':email', $email);
    $stmt->execute();
    
    $ret = $stmt->fetchAll(\PDO::FETCH_COLUMN|\PDO::FETCH_GROUP);
    $ret = array_map(function ($item) { return $item[0]; }, $ret);

    $points = $ret;
    $mandates = $ret;

    array_walk($points, function(&$item, $key) { GLOBAL $CATEGORIES; $item = $item * $CATEGORIES[$key]->getPoints(); });
    array_walk($mandates, function(&$item, $key) { GLOBAL $CATEGORIES; $item = $item * $CATEGORIES[$key]->getMandate(); });

    $mandates = array_sum($mandates);
    $points = array_sum($points);
    $level = $user->pointsToUserLevel($points);
    $badges = $user->getUserBadges($ret);

    return Array(
        "mandates" => $mandates,
        "points" => $points,
        "level" => $level,
        "badges" => $badges
    );
}

function stats(bool $useCache, User $user): Array{
    $userEmail = $user->getEmail();

    $stats = \cache\get("stats3-$userEmail");
    if($useCache && $stats){
        return $stats;
    }

    $stats = _countAppsByStatus($userEmail);
    $stats['active'] = array_sum($stats) - @$stats['archived'] - @$stats['draft'];

    $userPoints = \user\points($user);
    $stats = $stats + $userPoints;

    \cache\set("stats3-$userEmail", $stats, 0);
    return $stats;
}

function _countAppsByStatus(string $userEmail): Array{
    $email = \SQLite3::escapeString($userEmail);

    $sql = <<<SQL
        select json_extract(value, '$.status') as status,
            count(key) as cnt
        from applications
        where email = :email
        group by json_extract(value, '$.status')
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->bindValue(':email', $email);
    $stmt->execute();
    
    $ret = $stmt->fetchAll(\PDO::FETCH_COLUMN|\PDO::FETCH_GROUP);
    return array_map(function ($status) { return $status[0]; }, $ret);
}

/**
 * Returns all applications for current user by status.

 */
function appsConfirmedByCity(string $city): Array{
    $sql = <<<SQL
    select key, value
    from applications
    where email = :email
        and json_extract(value, '$.status') = :status
        and json_extract(value, '$.smCity') = :city
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->bindValue(':email', currentEmail());
    $stmt->bindValue(':status', 'confirmed');
    $stmt->bindValue(':city', $city);
    $stmt->execute();

    $apps = Array();
    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        $apps[$row[0]] = Application::withJson($row[1]);
    }
    return array_reverse($apps);
}

function nextCityToSent(){
    $sql = <<<SQL
        select json_extract(value, '$.smCity'),
            count(key) from applications
        where email = :email
            and json_extract(value, '$.status') = :status
            and json_extract(value, '$.smCity') is not null
        group by json_extract(value, '$.smCity')
        order by count(key) desc
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->bindValue(':email', currentEmail());
    $stmt->bindValue(':status', 'confirmed');
    $stmt->execute();

    $ret = $stmt->fetchAll();
    if(count($ret) == 0){
        return null;
    }
    return $ret[0][0];
}

function byName($name, $apiToken){
    if($apiToken !== API_TOKEN){
        throw new \Exception('Dostęp zabroniony');
    }
    $sql = <<<SQL
        select key, value 
        from users
        where lower(json_extract(value, '$.data')) like '%' || lower(:name) || '%'
        limit 10;
SQL;

    $stmt = \store\prepare($sql);
    $stmt->bindValue(':name', $name);
    $stmt->execute();

    $users = Array();
    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        $users[$row[0]] = new User($row[1]);
    }
    return $users;
}
