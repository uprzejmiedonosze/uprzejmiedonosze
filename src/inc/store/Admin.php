<?PHP namespace admin;

$INACTIVITY = '-12 months';
$WARNING = '-2 months';
$LAST_WARNING = '-14 days';
$BATCH_SIZE = 50;

function getInactiveUsers(): array {
    global $INACTIVITY, $BATCH_SIZE;
    $sql = <<<SQL
        select users.value
        from users
        join (
            select email,
                max(json_extract(value, '$.added')) old_app
            from applications
            group by 1
        ) apps on users.key = apps.email
        where apps.old_app < date('now', '$INACTIVITY')
            and json_extract(value, '$.removalWarningSent') is null
            and (json_extract(users.value, '$.updated') is null
                or json_extract(users.value, '$.updated') < date('now', '$INACTIVITY'))
            and json_extract(users.value, '$.deleted') is null
        order by apps.old_app asc
        limit $BATCH_SIZE
    SQL;

    return __getUsers($sql);
}

function getWarnedUsers(): array {
    global $INACTIVITY, $WARNING, $BATCH_SIZE;
    $sql = <<<SQL
        select users.value
        from users
        join (
            select email,
                max(json_extract(value, '$.added')) old_app
            from applications
            group by 1
        ) apps on users.key = apps.email
        where apps.old_app < date('now', '$INACTIVITY')
            and json_extract(users.value, '$.removalWarningSent') < date('now', '$WARNING')
            and (json_extract(users.value, '$.updated') is null
                or json_extract(users.value, '$.updated') < date('now', '$INACTIVITY'))
            and json_extract(users.value, '$.removal2ndWarningSent') is null
            and json_extract(users.value, '$.deleted') is null
        limit $BATCH_SIZE
    SQL;

    return __getUsers($sql);
}

function getUsersToRemove(): array {
    global $INACTIVITY, $WARNING, $LAST_WARNING, $BATCH_SIZE;
    $sql = <<<SQL
        select users.value
        from users
        join (
            select email,
                max(json_extract(value, '$.added')) old_app
            from applications
            group by 1
        ) apps on users.key = apps.email
        where apps.old_app < date('now', '$INACTIVITY')
            and json_extract(users.value, '$.removalWarningSent') < date('now', '$WARNING')
            and json_extract(users.value, '$.removal2ndWarningSent') < date('now', '$LAST_WARNING')
            and (json_extract(users.value, '$.updated') is null
                or json_extract(users.value, '$.updated') < date('now', '$INACTIVITY'))
            and json_extract(users.value, '$.deleted') is null
        limit $BATCH_SIZE
    SQL;

    return __getUsers($sql);
}


function __getUsers(string $sql): array {
    $stmt = \store\prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(\PDO::FETCH_FUNC,
        fn($json) => \user\User::withJson($json, dontDecode:true));
}

