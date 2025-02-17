<?PHP namespace global_stats;

use cache\Type;

/**
 * Returns number of new applications (by creation date)
 * during 30 days. 
 */
function appsByDay(bool $useCache=true){

    $stats = \cache\get(Type::GlobalStats, "appsByDay");
    if($useCache && $stats){
        return $stats;
    }

    $sql = <<<SQL
        select substr(json_extract(applications.value, '$.added'), 1, 10) as 'day',
            count(*) as cnt from applications
        where json_extract(applications.value, '$.status') not in ('draft', 'ready')
            and json_extract(applications.value, '$.added') < date('now')
        group by substr(json_extract(applications.value, '$.added'), 1, 10)
        order by 1 desc
        limit 30;
    SQL;

    $stats = \store\query($sql)->fetchAll(\PDO::FETCH_NUM);
    \cache\set(Type::GlobalStats, 'appsByDay', $stats);
    return $stats;
}

/**
 * Returns number of new applications (by creation date)
 * during 30 days. 
 */
function statsByDay(bool $useCache=true){

    $stats = \cache\get(Type::GlobalStats, "statsByDay");
    if($useCache && $stats){
        return $stats;
    }

    $today = (date('H') < 12)? "and json_extract(applications.value, '$.added') < date('now')": "";

    $sql = <<<SQL
        with a as (
            select substr(json_extract(value, '$.added'), 1, 10) as 'day',
                count(key) as cnt
            from applications
            where json_extract(value, '$.status') not in ('draft', 'ready')
                and json_extract(value, '$.added') >= date('now', '-1 month')
                -- $today
            group by 1
        ), u as (
            select substr(json_extract(value, '$.added'), 1, 10) as 'day',
                count(key) as cnt
            from users
            where json_extract(value, '$.added') >= date('now', '-1 month')
            group by 1
        )
        select a.day,
            a.cnt as acnt,
            u.cnt as ucnt
        from a
        left outer join u on a.day = u.day
        order by 1 desc;
    SQL;

    $stats = \store\query($sql)->fetchAll(\PDO::FETCH_NUM);
    \cache\set(Type::GlobalStats, 'statsByDay', $stats);
    return $stats;
}

/**
 * Returns number of new applications (by creation month)
 * in last year.
 */
function statsByYear(bool $useCache=true){

    $stats = \cache\get(Type::GlobalStats, "statsByYear");
    if($useCache && $stats){
        return $stats;
    }

    $sql = <<<SQL
        select min(substr(json_extract(applications.value, '$.added'), 1, 7)) as 'day',
            count(*) as acnt,
            u.cnt as ucnt
        from applications
        left outer join (
            select min(substr(json_extract(users.value, '$.added'), 1, 7)) as 'day',
                count(*) as cnt
            from users
            where substr(json_extract(users.value, '$.added'), 1, 4) > 2017
            group by substr(json_extract(users.value, '$.added'), 1, 7)
            order by 1 desc
            limit 35
        ) u on substr(json_extract(applications.value, '$.added'), 1, 7) = u.day
        where json_extract(applications.value, '$.status') not in ('draft', 'ready')
            and substr(json_extract(applications.value, '$.added'), 1, 4) > 2017
        group by substr(json_extract(applications.value, '$.added'), 1, 7)
        order by 1 desc
        limit 24;
    SQL;

    $stats = \store\query($sql)->fetchAll(\PDO::FETCH_NUM);
    \cache\set(Type::GlobalStats, 'statsByYear', $stats);
    return $stats;
}

/**
 * Returns number of applications per city.
 */
function statsByCarBrand(bool $useCache=true){
    $stats = \cache\get(Type::GlobalStats, "statsByCarBrand");
    if($useCache && $stats){
        return $stats;
    }

    $sql = <<<SQL
        select json_extract(value, '$.carInfo.brand') as city,
            count(key) as cnt
        from applications
        where json_extract(value, '$.status') not in ('draft', 'ready')
            and json_extract(value, '$.carInfo.brand') is not null
        group by json_extract(value, '$.carInfo.brand')
        order by 2 desc
        limit 10
    SQL;

    $stats = \store\query($sql)->fetchAll(\PDO::FETCH_NUM);
    \cache\set(Type::GlobalStats, 'statsByCarBrand', $stats);
    return $stats;
}

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 */
function mainPage(bool $useCache=true): array{
    $stats = \cache\get(Type::GlobalStats, "mainPage");
    if($useCache && $stats){
        return $stats;
    }

    global $SM_ADDRESSES;
    $sm = count($SM_ADDRESSES);

    $sql = <<<SQL
        select count(key) as cnt
        from applications
        where json_extract(value, '$.status') not in ('ready', 'draft', 'archive')
    SQL;
    $apps = intval(\store\query($sql)->fetchColumn());

    $sql = <<<SQL
        select count(key) as cnt
        from users
    SQL;
    $users = intval(\store\query($sql)->fetchColumn());

    $patrons = count(\patronite\active());

    $stats = Array('apps' => $apps, 'users' => $users, 'sm' => $sm, 'patrons' => $patrons);
    \cache\set(Type::GlobalStats, 'mainPage', $stats);
    return $stats;
}