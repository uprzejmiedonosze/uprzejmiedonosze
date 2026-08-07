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
 * Returns number of new applications (by creation month) in last year,
 * split into the ones addressed to Straż Miejska and the ones addressed
 * to Policja, plus the number of new users.
 *
 * Rows are [month, smCnt, policeCnt, usersCnt]; smCnt + policeCnt is the
 * same total this function used to return as a single column.
 *
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 */
function statsByYear(bool $useCache=true){

    // Cache key is versioned because the row shape changed: an entry written by
    // the previous single-column version would feed the user count into the
    // Policja series until it expired.
    $stats = \cache\get(Type::GlobalStats, "statsByYear2");
    if($useCache && $stats){
        return $stats;
    }

    global $SM_ADDRESSES, $POLICE_ADDRESSES;

    // A report only ever stores the lowercased unit key (Application::smCity),
    // so the recipient has to be derived the way SM::resolve() derives it at
    // render time: #stopagresji always goes to Policja, otherwise sm.json wins
    // over police.json for the handful of keys present in both, and anything
    // unresolvable falls back to the SM side ($SM_ADDRESSES['_nieznane']).
    // Folding that together, a report is Policja iff stopAgresji is set or its
    // key is police-only -- which is the single list the query needs.
    $policeOnly = array_values(array_diff(
        array_keys($POLICE_ADDRESSES),
        array_keys($SM_ADDRESSES)
    ));

    $sql = <<<SQL
        with police_keys as (
            select p.value as k from json_each(:policeKeys) p
        ), classified as (
            select substr(json_extract(app.value, '$.added'), 1, 7) as 'month',
                case when coalesce(json_extract(app.value, '$.stopAgresji'), 0)
                        or lower(coalesce(json_extract(app.value, '$.smCity'), ''))
                            in (select k from police_keys)
                     then 1 else 0 end as is_police
            from applications app
            where json_extract(app.value, '$.status') not in ('draft', 'ready')
                and json_extract(app.value, '$.added') >= date('now', '-24 months')
        ), a as (
            select month,
                sum(1 - is_police) as sm_cnt,
                sum(is_police) as police_cnt
            from classified
            group by 1
        ), u as (
            select substr(json_extract(value, '$.added'), 1, 7) as 'month',
                count(key) as cnt
            from users
            where json_extract(value, '$.added') >= date('now', '-24 months')
            group by 1
        )
        select a.month,
            a.sm_cnt as smcnt,
            a.police_cnt as pcnt,
            u.cnt as ucnt
        from a
        left outer join u on a.month = u.month
        order by 1 desc
        limit 24;
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->execute([':policeKeys' => json_encode($policeOnly)]);
    $stats = $stmt->fetchAll(\PDO::FETCH_NUM);
    \cache\set(Type::GlobalStats, 'statsByYear2', $stats);
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

    $patrons = count(\patronite\active(useCache:false));

    $stats = Array('apps' => $apps, 'users' => $users, 'sm' => $sm, 'patrons' => $patrons);
    \cache\set(Type::GlobalStats, 'mainPage', $stats);
    return $stats;
}