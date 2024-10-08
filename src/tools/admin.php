<?PHP namespace admin;

use app\Application;
use recydywa\Recydywa;
use user\User;

require_once(__DIR__ . '/../../vendor/autoload.php');
require(__DIR__ . '/../inc/store/Store.php');


function removeDrafts($olderThan=10, $dryRun=true){ // days
    removeAppsByStatus($olderThan, 'draft', $dryRun);
}

/**
* Removes old apps in ready status and it's files.
*/
function removeReadyApps($olderThan = 30, $dryRun=true){
    removeAppsByStatus($olderThan, 'ready', $dryRun);
}

/**
 * Removes user by given $email
 */
function removeUser($email, $dryRun=true){
    if(!isset($email)){
        throw new \Exception("No email provided\n");
    }

    $email = \SQLite3::escapeString($email);

    $userJson = \store\get('users', $email);
    if(!$userJson){
        throw new \Exception("Trying to remove nonesiting user: $email");
    }
    $user = new User($userJson);

    $apps = getAllApplicationsByEmail($email);

    echo "Usuwam wszystkie zgłoszenia użytkownika '$email'\n";
    foreach($apps as $app){
        removeApplication($app, $dryRun);
    }

    $cdn2UserFolder = __DIR__ . "/../../cdn2/{$user->number}/";
    if(file_exists($cdn2UserFolder) && filetype($cdn2UserFolder) == 'dir'){
        echo "Kasuję folder użytkownika\n";
        if(!$dryRun){
            $cmd = sprintf("rm -rf %s", escapeshellarg($cdn2UserFolder));
            exec($cmd, $output);
            unset($output);
        }
    }

    echo "Zamazuję dane użytkownika w bazie\n";
    if($dryRun){
        return;
    }
    // adding empty user under a different key
    $time = date(DT_FORMAT);
    $user->data->name = 'DELETED';
    $user->data->msisdn = 'DELETED';
    $user->data->address = 'DELETED';
    $user->data->email = md5($email . $time);
    $user->data->emailMD5 = md5($email);

    $user->deleted = $time;
    $user->applications = Array();
    \store\set('users', $user->data->email, json_encode($user));

    // removing old user
    \store\delete('users', $email);
}

/**
 * Removes application
 */
function removeApplication($app, $dryRun){
    global $STATUSES;
    $added = (isset($app->added))? " dodane {$app->added}": "";
    $number = (isset($app->number))? "{$app->number} ($app->id)": "($app->id)";
    $status = $STATUSES[$app->status]->name;
    $email = (isset($app->user->email))? " użytkownika {$app->user->email}": " użytkownika @anonim";
    echo "Usuwam zgłoszenie numer $number [$status]$email$added\n";
    if(isset($app->carImage)){
        removeFile($app->carImage->url, $dryRun);
        removeFile($app->carImage->thumb, $dryRun);
    }
    if(isset($app->contextImage)){
        removeFile($app->contextImage->url, $dryRun);
        removeFile($app->contextImage->thumb, $dryRun);
    }
    if(isset($app->carInfo) && isset($app->carInfo->plateImage)){
        removeFile($app->carInfo->plateImage, $dryRun);
    }

    echo " zgłoszenie oraz jego pliki usunięte;\n\n";
    if($dryRun){
        return;
    }
    \store\delete('applications', $app->id);
}

function removeFile($fileName, $dryRun){
    $file = __DIR__ . "/../../$fileName";
    if(!isset($file) || empty($fileName)){
        return;
    }
    if(!file_exists($file)){
        echo " ! plik '$fileName' nie istnieje\n";
        return;
    }
    if(filetype($file) !== 'file'){
        echo " ! '$fileName' nie jest plikiem\n";
        return;
    }
    echo " - usuwam '$fileName'\n";
    if(!$dryRun){
        unlink($file);
    }
}

/**
 * Generic function to remove apps by status
 */
function removeAppsByStatus($olderThan, $status, $dryRun){ // days
    if($status !== 'draft' && $status !== 'ready'){
        throw new \Exception("Refuse to remove apps in '$status' status.");
    }

    $apps = getAllApplicationsByStatus($status);

    $date = date_create();
    date_sub($date, date_interval_create_from_date_string("$olderThan days"));
    $latest = date_format($date, "Y-m-d");;

    foreach($apps as $app){
        if(isset($app->added)){
            if($app->added > $latest){
                echo "Not removing $app->id from $app->added by {$app->user->email} as it's still fresh\n";
                continue;
            }
        }
        if($app->status !== $status) { // just for safety
            continue;
        }
        removeApplication($app, $dryRun);
    }
}

/**
 * Returns all applications by status.
 */
function getAllApplicationsByStatus($status){
    global $store;
    $sql = <<<SQL
        select key, value
        from applications
        where json_extract(value, '$.status') = :status;
    SQL;
    $stmt = $store->prepare($sql);
    $stmt->bindValue(':status', $status);
    $stmt->execute();

    $apps = Array();
    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        $apps[$row[0]] = Application::withJson($row[1]);
    }
    return $apps;
}

function getAllUsers() {
    global $store;
    $sql = <<<SQL
        select key, value
        from users;
    SQL;
    $stmt = $store->prepare($sql);
    $stmt->execute();

    $users = Array();

    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        $users[$row[0]] = new User($row[1]);
    }
    return $users;
}

/**
 * Returns all applications by user.
 * 
 * @email
 * @onlyWithNumber - ignore drafts and ready apps
 * @SuppressWarnings(PHPMD.MissingImport)
 */
function getAllApplicationsByEmail($email, $onlyWithNumber = null){
    global $store;

    $onlyWithNumberSQL = ($onlyWithNumber)? " and json_extract(value, '$.status') not in ('ready', 'draft')": "";

    $sql = <<<SQL
        select key, value
        from applications
        where json_extract(value, '$.user.email') = :email $onlyWithNumberSQL;
    SQL;
    $stmt = $store->prepare($sql);
    $stmt->bindValue(':email', $email);
    $stmt->execute();

    $apps = Array();

    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        $apps[$row[0]] = Application::withJson($row[1]);
    }
    return $apps;
}

function upgradeAllApps($version, $dryRun){
    $users = getAllUsers();
    foreach ($users as $email => $user) {
        echo date(DT_FORMAT) . " migrating user $email:\n";
        if(!$dryRun){
            \store\set('users', $email, json_encode($user));
        }
        $apps = getAllApplicationsByEmail($email, false);
        foreach ($apps as $appId => $app) {
            updateApp($app, $version, $dryRun);
        }
    }
}

function updateApp($app, $version, $dryRun) {
    global $STATUSES;
    $added = (isset($app->added))? " added on {$app->added}": "";
    $number = (isset($app->number))? "{$app->number} ($app->id)": "($app->id)";
    $status = $STATUSES[$app->status]->name;
    echo "  - migrating app $number [$status] by {$app->user->email}$added\n";
    if($app->version < '2.1.0') {
        $app->inexactHour = true;
    }
    $app->version = $version;

    if($dryRun){
        return;
    }
    \store\set('applications', $app->id, json_encode($app));
}

function refreshRecydywa() {
    global $store;
    $sql = <<<SQL
        select json_extract(value, '$.carInfo.plateId') as plateId,
            count(key) as appsCnt,
            count(distinct email) as usersCnt,
            count(distinct json_extract(value, '$.address.city')) as citiesCnt
        from applications 
        where json_extract(value, '$.status') not in ('archived', 'ready', 'draft')
        group by 1;
    SQL;

    $stmt = $store->prepare($sql);
    $stmt->execute();

    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        $plateId = trim(strtoupper($row[0]));
        echo "$plateId set\n";
        $rec = Recydywa::withValues($row[1], $row[2], $row[3]);
        
        \store\set('recydywa', "$plateId v2", json_encode($rec));
    }
}




removeDrafts(10, false);
removeReadyApps(30, false);

// removeUser('szymon@nieradka.net', false);

// upgradeAllApps('2.3.0', false);