<?PHP namespace admin;

$INACTIVITY = '-12 months';
$WARNING = '-2 months';
$LAST_WARNING = '-14 days';
$BATCH_SIZE = 200;

function getInactiveUsers(): array {
    global $INACTIVITY, $BATCH_SIZE;
    __ensureLastApplicationDates();
    $sql = <<<SQL
        select users.value
        from users
        join temp.apps_last_application apps on users.key = apps.email
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
    __ensureLastApplicationDates();
    $sql = <<<SQL
        select users.value
        from users
        join temp.apps_last_application apps on users.key = apps.email
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
    __ensureLastApplicationDates();
    $sql = <<<SQL
        select users.value
        from users
        join temp.apps_last_application apps on users.key = apps.email
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

// getInactiveUsers/getWarnedUsers/getUsersToRemove all need "last application
// date per user", aggregated over the full `applications` table. That scan is
// the expensive part (multi-second full table read), so compute it once per
// connection and let all three share it instead of repeating it 3x per run.
function __ensureLastApplicationDates(): void {
    \store\query(<<<SQL
        create temp table if not exists apps_last_application as
        select email, max(json_extract(value, '$.added')) old_app
        from applications
        group by 1
    SQL);
}

function __getUsers(string $sql): array {
    $stmt = \store\prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(\PDO::FETCH_FUNC,
        fn($json) => \user\User::withJson($json, dontDecode:true));
}

