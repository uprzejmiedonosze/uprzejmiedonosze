<?PHP namespace user;

require(__DIR__ . '/../dataclasses/User.php');

use cache\Type;

const TABLE = 'users';
$currentUser = null;

current();

// getUser
function get(string $email, bool $dontDecode=false): User {
    $json = \store\get(TABLE, $email);
    if(!$json){
        throw new \Exception("Próba pobrania nieistniejącego użytkownika '$email'", 404);
    }
    $user = new User($json, $dontDecode);
    setSentryTag("userNumber", $user->getNumber() ?? 0);
    return $user;
}

function canShareRecydywa(string $email): bool {
    return get($email, true)->shareRecydywa();
}

// saveUser
function save(User $user, bool $dontDecode=false): void {
    if ($dontDecode) {
        \store\set(TABLE, $user->getEmail(), json_encode($user));
        return;
    }
    if(!isset($user->number)){
        $user->number = nextNumber();
    }
    \store\set(TABLE, $user->getEmail(), $user->encode());
}

// getCurrentUser
function current(): User{
    global $currentUser;
    if(is_null($currentUser)){
        try{
            $currentUser = get(currentEmail());
        } catch(\Exception $e){
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

const APPS_PAGE_SIZE = 100;
const APPS_PAGE_MAX = 500;

/**
 * Parses the list query shared by the web list (/app/list, /app/partial),
 * REST /api/rest/user/apps and the MCP list_reports tool. The page size is
 * always clamped — only internal callers (export, account removal) may call
 * apps() without a limit.
 *
 * @return array{status: string, search: string, limit: int, offset: int}
 */
function appsQueryFromParams(array $params): array {
    $limit = (int)($params['limit'] ?? APPS_PAGE_SIZE);
    return [
        'status' => (string)($params['status'] ?? 'all'),
        'search' => (string)($params['search'] ?? $params['q'] ?? ''),
        'limit'  => $limit > 0 ? min($limit, APPS_PAGE_MAX) : APPS_PAGE_SIZE,
        'offset' => max(0, (int)($params['offset'] ?? 0)),
    ];
}

/**
 * The current user's reports, newest first.
 *
 * $status: 'all' (no drafts), 'allWithDrafts', 'sendable' (every status with
 * "sendable": true in statuses.json) or a concrete status id.
 *
 * $search is matched in PHP against Application::searchText(), because
 * address and comments are stored encrypted. Rows are streamed and decoded
 * one by one until a full page is collected, so memory stays bounded by
 * $limit even for accounts with >10k reports.
 */
function apps(User $user, string $status = 'all', string $search = '', int $limit = 0, int $offset = 0): array {
    global $STATUSES;

    $params = [':email' => $user->getEmail()];

    $whereStatus = <<<SQL
        and json_extract(value, '$.status') not in ('ready', 'draft')
    SQL;
    if ($status == 'allWithDrafts') {
        $whereStatus = '';
    } elseif ($status == 'sendable') {
        $sendable = array_keys(array_filter($STATUSES, fn($s) => $s->sendable ?? false));
        $placeholders = [];
        foreach ($sendable as $i => $s) {
            $placeholders[] = ":status$i";
            $params[":status$i"] = $s;
        }
        $whereStatus = "and json_extract(value, '$.status') in (" . implode(',', $placeholders) . ')';
    } elseif ($status !== 'all') {
        $whereStatus = <<<SQL
            and json_extract(value, '$.status') = :status
        SQL;
        $params += [':status' => $status];
    }

    $search = trim($search);
    if (in_array($search, ['all', '%'], true))
        $search = '';

    $limitOffset = '';
    if ($limit > 0 && $search === '') {
        $params += [':limit' => $limit, ':offset' => $offset];
        $limitOffset = 'limit :limit offset :offset';
    }

    $sql = <<<SQL
        select value, email
        from applications
        where email = :email
            $whereStatus
        order by json_extract(value, '$.seq') desc,
            json_extract(value, '$.added') desc
        $limitOffset
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->execute($params);

    if ($search === '')
        return $stmt->fetchAll(\PDO::FETCH_FUNC,
            fn($json, $email) => \app\Application::withJson($json, $email));

    $apps = [];
    $skip = $offset;
    while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
        $app = \app\Application::withJson($row[0], $row[1]);
        if (mb_stripos($app->searchText(), $search) === false)
            continue;
        if ($skip-- > 0)
            continue;
        $apps[] = $app;
        if ($limit > 0 && count($apps) >= $limit)
            break;
    }
    $stmt->closeCursor();
    return $apps;
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
    log_debug("nextUserNumber $number + 1");
    return $number + 1;
}

function points(User $user): Array{
    $userEmail = $user->getEmail();

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
    $stmt->bindValue(':email', $userEmail);
    $stmt->execute();

    $ret = $stmt->fetchAll(\PDO::FETCH_COLUMN|\PDO::FETCH_GROUP);
    $ret = array_map(function ($item) { return $item[0]; }, $ret);

    $points = $ret;
    $mandates = $ret;

    array_walk($points, function(&$item, $key) { global $CATEGORIES; $item = $item * $CATEGORIES[$key]->getPoints(); });
    array_walk($mandates, function(&$item, $key) { global $CATEGORIES; $item = $item * $CATEGORIES[$key]->getMandate(); });

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

    $stats = \cache\get(Type::UserStats, $userEmail);
    if($useCache && $stats){
        return $stats;
    }

    $stats = _countAppsByStatus($userEmail);
    $stats['active'] = array_sum($stats)
        - ($stats['archived'] ?? 0)
        - ($stats['draft'] ?? 0)
        - ($stats['ready'] ?? 0);

    $userPoints = \user\points($user);
    $stats = $stats + $userPoints;

    \cache\set(Type::UserStats, $userEmail, $stats);
    return $stats;
}

function _countAppsByStatus(string $userEmail): Array{
    $sql = <<<SQL
        select json_extract(value, '$.status') as status,
            count(key) as cnt
        from applications
        where email = :email
        group by json_extract(value, '$.status')
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->bindValue(':email', $userEmail);
    $stmt->execute();

    $ret = $stmt->fetchAll(\PDO::FETCH_COLUMN|\PDO::FETCH_GROUP);
    return array_map(function ($status) { return $status[0]; }, $ret);
}
