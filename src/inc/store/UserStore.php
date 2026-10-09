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

/** Małe litery bez polskich znaków diakrytycznych: „Wrocław”, „WROCŁAW” i „wroclaw” to to samo wyszukiwanie. */
function searchFold(string $text): string {
    return strtr(mb_strtolower($text), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
}

/**
 * Tekst zgłoszenia, po którym szuka lista („Szukaj”): numer, sygnatura, adres, tablice, komentarze, kategoria i miejscowość SM.
 * Adres i komentarze są w bazie szyfrowane (Application::encode), więc zgłoszenie musi być już odszyfrowane.
 */
function searchText(\app\Application $app): string {
    $fields = [$app->number ?? '', $app->externalId ?? '', $app->address->address ?? '', $app->carInfo->plateId ?? '',
        $app->userComment ?? '', $app->privateComment ?? '', $app->smCity ?? ''];
    try {
        $fields[] = $app->getCategory()->getFormal();
    } catch (\Throwable $e) {
        // nieznana kategoria – reszta pól wystarczy
    }
    return searchFold(implode(' ', array_map('strval', $fields)));
}

/** Najwięcej zgłoszeń zwracanych jednym wyszukiwaniem (zwykłe pobranie 7000+ zgłoszeń potrafiło przekroczyć memory_limit). */
const SEARCH_MAX_RESULTS = 5000;

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
    if ($status == 'allWithDrafts') {
        $whereStatus = '';
    } elseif ($status == 'active') { // what the web list shows by default: everything but archived (and drafts)
        $whereStatus = <<<SQL
            and json_extract(value, '$.status') not in ('ready', 'draft', 'archived')
        SQL;
    } elseif ($status !== 'all') {
        $whereStatus = <<<SQL
            and json_extract(value, '$.status') = :status
        SQL;
        $params += [':status' => $status];
    }

    // Szukanie po odszyfrowanym tekście zgłoszenia w PHP (w bazie adres i komentarze są zaszyfrowane, a Unicode w JSON-ie
    // escapowany, więc SQL-owe LIKE ich nie widzi): bez rozróżniania wielkości liter i polskich znaków. "%" (domyślna
    // wartość REST) i pusty tekst = bez filtra. Strona (limit/offset) jest wtedy wycinana po filtrowaniu.
    $needle = searchFold(trim($search));
    $searching = $search !== 'all' && $needle !== '' && $needle !== '%';
    if ($searching) {
        $limitOffset = '';
        unset($params[':limit'], $params[':offset']);
    }
    $whereSearch = '';

    $sql = <<<SQL
        select value, email
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

    if (!$searching)
        return $stmt->fetchAll(\PDO::FETCH_FUNC,
            fn($json, $email) => \app\Application::withJson($json, $email));

    // Kursor zamiast fetchAll(): w pamięci jest naraz jedno zgłoszenie (konta mają nawet kilkanaście tysięcy, a samo pobranie
    // 7000 zgłoszeń potrafiło przekroczyć memory_limit). Zatrzymujemy się, gdy mamy `offset + limit` trafień – dalsze strony
    // dobierają kolejne, a lista nie potrzebuje łącznej liczby wyników. Bez limitu (lub większym) zwracamy najwyżej SEARCH_MAX_RESULTS.
    $max = $limit > 0 ? min($limit, SEARCH_MAX_RESULTS) : SEARCH_MAX_RESULTS;
    $apps = [];
    $matched = 0;
    $scanned = 0;
    while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
        $app = \app\Application::withJson($row[0], $row[1]);
        if (!str_contains(searchText($app), $needle)) {
            if (++$scanned % 500 === 0)
                gc_collect_cycles(); // obiekty zgłoszeń mają odwołania cykliczne – bez tego zwalniają się dopiero przy GC
            continue;
        }
        if ($matched++ < $offset)
            continue;
        $apps[] = $app;
        if (count($apps) >= $max)
            break;
    }
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
