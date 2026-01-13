<?PHP namespace admin;

$INACTIVITY = '-12 months';
$WARNING = '-2 months';
$LAST_WARNING = '-14 days';

function getInactiveUsers(): array {
    global $INACTIVITY;
    $sql = <<<SQL
        select users.value
        from users
        join (
            select email,
                max(json_extract(value, '$.added')) old_app
            from applications
            group by 1
            order by 2
        ) apps on users.key = apps.email
        where apps.old_app < date('now', '$INACTIVITY')
            and json_extract(value, '$.removalWarningSent') is null
            and json_extract(users.value, '$.updated') < date('now', '$INACTIVITY')
            and json_extract(users.value, '$.deleted') is null
    SQL;

    return __getUsers($sql);
}

function getWarnedUsers(): array {
    global $INACTIVITY, $WARNING;
    $sql = <<<SQL
        select value
        from users
        where json_extract(value, '$.removalWarningSent') < date('now', '$WARNING')
            and json_extract(value, '$.updated') < date('now', '$INACTIVITY')
            and json_extract(value, '$.removal2ndWarningSent') is null
            and json_extract(users.value, '$.deleted') is null
    SQL;

    return __getUsers($sql);
}

function getUsersToRemove(): array {
    global $INACTIVITY, $WARNING, $LAST_WARNING;
    $sql = <<<SQL
        select value
        from users
        where json_extract(value, '$.removalWarningSent') < date('now', '$WARNING')
            and json_extract(value, '$.removal2ndWarningSent') < date('now', '$LAST_WARNING')
            and json_extract(value, '$.updated') < date('now', '$INACTIVITY')
            and json_extract(users.value, '$.deleted') is null
    SQL;

    return __getUsers($sql);
}


function __getUsers(string $sql): array {
    $stmt = \store\prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(\PDO::FETCH_FUNC,
        fn($json) => \user\User::withJson($json, dontDecode:true));
}



function removeUser($email, $dryRun=true): string {
    if(!isset($email)){
        throw new \Exception("No email provided\n");
    }

    $email = \SQLite3::escapeString($email);
    $user = \user\get($email, dontDecode:true);
    $apps = \user\apps($user, 'allWithDrafts');

    $output = "Usuwam wszystkie zgłoszenia użytkownika '$email'\n";
    foreach($apps as $app){
        $output .= removeApplication($app, $dryRun);
    }

    $cdn2UserFolder = __DIR__ . "/../../cdn2/{$user->number}/";
    if(file_exists($cdn2UserFolder) && filetype($cdn2UserFolder) == 'dir'){
        $output .= "Kasuję folder użytkownika\n";
        if(!$dryRun){
            $cmd = sprintf("rm -rf %s", escapeshellarg($cdn2UserFolder));
            exec($cmd, $output);
            unset($output);
        }
    }

    $output .= "Zamazuję dane użytkownika w bazie\n";
    if($dryRun){
        return $output;
    }
    // adding empty user under a different key
    $time = date(DT_FORMAT);
    $user->data->name = 'DELETED';
    $user->data->msisdn = 'DELETED';
    $user->data->edelivery = 'DELETED';
    $user->data->address = 'DELETED';
    $user->data->email = md5($email . $time);
    $user->emailMD5 = md5($email);

    $user->deleted = $time;
    $_SESSION['user_id'] = 'fake';
    \user\save($user, dontDecode:true);

    // removing old user
    \store\delete('users', $email);

    return $output;
}

function removeApplication($app, $dryRun): string{
    global $STATUSES;
    $added = (isset($app->added))? " dodane {$app->added}": "";
    $number = (isset($app->number))? "{$app->number} ($app->id)": "($app->id)";
    $status = $STATUSES[$app->status]->name;
    $email = (isset($app->email))? " użytkownika {$app->email}": " użytkownika @anonim";
    
    $output = "Usuwam zgłoszenie numer $number [$status]$email$added\n";
    if(isset($app->carImage)){
        $output .= removeFile($app->carImage->url, $dryRun);
        $output .= removeFile($app->carImage->thumb, $dryRun);
    }
    if(isset($app->contextImage)){
        $output .= removeFile($app->contextImage->url, $dryRun);
        $output .= removeFile($app->contextImage->thumb, $dryRun);
    }
    if(isset($app->carInfo) && isset($app->carInfo->plateImage)){
        $output .= removeFile($app->carInfo->plateImage, $dryRun);
    }

    $output .= " zgłoszenie oraz jego pliki usunięte;\n\n";
    if($dryRun){
        return $output;
    }
    \store\delete('applications', $app->id);
    return $output;
}

function removeFile($fileName, $dryRun){
    $file = __DIR__ . "/../../$fileName";
    if(!isset($file) || empty($fileName)){
        return;
    }
    if(!file_exists($file)){
        return " ! plik '$fileName' nie istnieje\n";
    }
    if(filetype($file) !== 'file'){
        return " ! '$fileName' nie jest plikiem\n";
    }
    if(!$dryRun){
        unlink($file);
    }
    return " - usuwam '$fileName'\n";
}
