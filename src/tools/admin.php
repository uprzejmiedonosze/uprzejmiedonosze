<?PHP namespace admin;

require_once(__DIR__ . '/../../vendor/autoload.php');
require_once(__DIR__ . '/../inc/include.php');
require_once(__DIR__ . '/../inc/handlers/WebhooksHandler.php');

use app\Application;
use recydywa\Recydywa;
use user\User;

$interrupt = false;

function shutdown() {
    global $interrupt;
    $interrupt = true;
    echo "\nStopping job...\n";
}

register_shutdown_function("\\admin\\shutdown"); // Handle END of script

declare(ticks = 1); // Allow posix signal handling
pcntl_signal(SIGINT, "\\admin\\shutdown");
pcntl_signal(SIGTERM, "\\admin\\shutdown");

/**
 * Removes user by given $email
 */
function removeUser($email, $dryRun=true){
    if(!isset($email)){
        throw new \Exception("No email provided\n");
    }

    $email = \SQLite3::escapeString($email);
    $user = \user\get($email, dontDecode:true);
    $apps = \user\apps($user, 'allWithDrafts');

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
    $user->data->edelivery = 'DELETED';
    $user->data->address = 'DELETED';
    $user->data->email = md5($email . $time);
    $user->emailMD5 = md5($email);

    $user->deleted = $time;
    $_SESSION['user_id'] = 'fake';
    \user\save($user);

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
    $email = (isset($app->email))? " użytkownika {$app->email}": " użytkownika @anonim";
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
    global $interrupt;
    if($status !== 'draft' && $status !== 'ready'){
        throw new \Exception("Refuse to remove apps in '$status' status.");
    }

    $apps = getAllApplicationsByStatus($status);

    $date = date_create();
    date_sub($date, date_interval_create_from_date_string("$olderThan days"));
    $latest = date_format($date, "Y-m-d");;

    foreach($apps as $app){
        if ($interrupt) exit;
        if(isset($app->added)){
            if($app->added > $latest){
                echo "Not removing $app->id from $app->added by {$app->email} as it's still fresh\n";
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
    $sql = <<<SQL
        select key, value, email
        from applications
        where json_extract(value, '$.status') = :status;
    SQL;
    $stmt = \store\prepare($sql);
    $stmt->bindValue(':status', $status);
    $stmt->execute();

    $apps = Array();
    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        $apps[$row[0]] = Application::withJson($row[1], $row[2]);
    }
    return $apps;
}

function upgradeAllUsers($dryRun=true) {
    global $interrupt;
    $sql = <<<SQL
        select key, value
        from users;
    SQL;
    $stmt = \store\prepare($sql);
    $stmt->execute();

    $users = array();
    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        $users[$row[0]] = $row[1];
    }
    foreach ($users as $email => $json) {
        if ($interrupt) exit;
        if (!fakeFirebaseId($email)) {
            continue;
        }
        echo date(DT_FORMAT) . " migrating user $email:\n";  
        $user = new User($json);
        unset($user->data->myAppsSize);
        unset($user->data->autoSend);
        unset($user->data->exposeData);

        if(!$dryRun) {
            \user\save($user);
        }
    }
}

function getTopAppsToMigrate(string $version): array {
    $sql = <<<SQL
        select key, email, value
        from applications
        where json_extract(value, '$.version') <> :version
        order by key
        limit 1000;
    SQL;
    $stmt = \store\prepare($sql);
    $stmt->bindValue(':version', $version);
    $stmt->execute();

    $apps = Array();
    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        $apps[$row[0]] = [$row[1], $row[2]];
    }
    return $apps;
}


function upgradeAllApps($version, $dryRun=true){
    global $interrupt;
    $apps = Array(1);
    $counter = 1;
    while(count($apps)) {
        if ($interrupt) exit;
        echo "\nGetting top 1K apps to migrate. Batch $counter\n\n";
        $apps = getTopAppsToMigrate($version);
        foreach ($apps as $appId => $app) {
            if ($interrupt) exit;

            $email = $app[0];
            $json = $app[1];
            updateApp($json, $email, $version, $dryRun);
        }
        $counter++;
    }
}

function updateApp(string $json, string $email, string $version, bool $dryRun) {
    $encoded = json_decode($json);
    if (!fakeFirebaseId($email)) {
        return;
    }
    $app = Application::withJson($json, $email);

    $number = (isset($app->number))? "{$app->number} ($app->id)": "($app->id)";
    echo "  - migrating app $number by {$email}\n";
    $app->version = $version;

    if (!isset($app->added)) # one time fix
        $app->added = $app->date;

    unset($app->user->myAppsSize);
    unset($app->user->autoSend);
    unset($app->user->exposeData);
    if($dryRun){
        return;
    }
    \app\save($app);
}

function unsentApp($appId) {
    $app = \app\get($appId);
    $app->setStatus('sending-failed', true);
    unset($app->sent);
    \app\save($app);
}

function refreshRecydywa() {
    global $interrupt;
    $sql = <<<SQL
        select plateId,
            count(key) as appsCnt,
            count(distinct email) as usersCnt
        from applications 
        where json_extract(value, '$.status') not in ('archived', 'ready', 'draft')
        group by 1;
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->execute();

    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        if ($interrupt) exit;
        $cleanPlateId = \recydywa\cleanPlateId($row[0]);
        echo "$cleanPlateId set\n";
        $recydywa = Recydywa::withValues($row[1], $row[2], $row[3]);

        \cache\set(type:\cache\Type::Recydywa, key:$cleanPlateId, value:$recydywa, flag:0, expire:0);
        \store\set('recydywa', "$cleanPlateId v2", json_encode($recydywa));
    }
}

$firebaseDb = null;
function fakeFirebaseId(string $email): bool {
    global $firebaseDb;
    if (!$firebaseDb) {
        $firebaseDb = json_decode(file_get_contents(__DIR__ . '/../../save_file.json'), true);
        $firebaseDb = array_column($firebaseDb['users'], 'localId', 'email');
    }

    if (!isset($firebaseDb[$email])) {
        $_SESSION['user_id'] = null;
        //echo "  error migrating user $email: No firebase id\n";
        return false;
    }

    $_SESSION['user_id'] = $firebaseDb[$email];
    return true;
}

function processWebhook(string $id): void {
    $event = \webhook\get($id);

    $payload = $event['event-data'];
    $appId = $payload['user-variables']['appid'];
    $recipient = $payload['recipient'];
    
    if(($payload['user-variables']['environment'] ?? 'prod') !== environment()) {
        \webhook\mark($id, 'other environment, ignoring');
        echo "other environment, ignoring";
        return;
    }

    if(isset($payload['user-variables']['nofitication'])) {
        // this is a notification triggered by an email sent by this webhook
        // so I have to ignore it not to trigger an endless loop
        \webhook\mark($id, 'this is notification, ignoring');
        echo "this is notification, ignoring";
        return;
    }
    $mailEvent = new \MailEvent($payload);

    \semaphore\acquire($appId);

    try {
        $application = \app\get($appId);
    } catch (\Exception $e) {
        if (isProd())
            throw $e;
        \webhook\mark($id, 'app already removed, ignoring');
        echo 'app already removed, ignoring';
        return;
    }
    

    if (!$application->wasSent()) {
        echo "mailgun webhook error, Application $appId was not sent!";
        return;
    }

    $comment = $mailEvent->formatComment();
    if ($comment) $application->addComment("mailer", $comment, $mailEvent->status);
    $ccToUser = $application->email == $recipient;

    if ($recipient == MAILER_FROM) {
        // this is BCC to Uprzejmie Donoszę, ignore it
        \webhook\mark($id, 'bcc to ud@, ignoring');
        \semaphore\release($appId);
        echo 'bcc to ud@, ignoring';
    }

    if (!$ccToUser) {
        // set sent status to accepted only if empty
        if ($mailEvent->status == 'accepted' && $application->status == 'confirmed')
            $application->setStatus('sending', true);
        if ($mailEvent->status == 'problem')
            $application->setStatus('sending-problem', true);
        if ($mailEvent->status == 'failed')
            $application->setStatus('sending-failed', true);
        if ($mailEvent->status == 'delivered')
            $application->setStatus('confirmed-waiting', true);
    }

    $application = \app\save($application);
    \webhook\mark($id);
    \semaphore\release($appId);

    if ($mailEvent->status == 'failed' && !$ccToUser)
        (new \MailGun())->notifyUser($application,
            "Nie udało się nam dostarczyć wiadomości zgłoszenia {$application->getNumber()}",
            $mailEvent->getReason(),
            $recipient);

    echo "OK";
}


//removeAppsByStatus(olderThan:10, status:'draft', dryRun:false);
//removeAppsByStatus(olderThan:30, status:'ready', dryRun:false);
//upgradeAllUsers(false);
//refreshRecydywa();
//upgradeAllApps('2.5.2', false);

processWebhook('dPwlXqMyTvWm1MnT6C0C3g');
