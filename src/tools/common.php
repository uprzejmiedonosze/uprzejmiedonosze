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

    $cdn2UserFolder = ROOT . \storage\cdnPrefix() . "/{$user->number}/";
    if(file_exists($cdn2UserFolder) && filetype($cdn2UserFolder) == 'dir'){
        echo "Kasuję folder użytkownika\n";
        if(!$dryRun){
            rmdirRecursive($cdn2UserFolder);
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
    \user\save($user, dontDecode:true);

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
        if ($app->contextImage->galleryReady ?? false) {
            $thumb  = $app->contextImage->thumb;
            $prefix = \storage\cdnPrefix();
            removeFile($prefix . '/gallery/' . \crypto\encode($thumb, CRYPTO_KEY, CRYPTO_IV) . '.jpg', $dryRun);
            removeFile($prefix . '/gallery/' . \crypto\encode("{$thumb}?pixelate", CRYPTO_KEY, CRYPTO_IV) . '.jpg', $dryRun);
        }
    }
    if(isset($app->thirdImage)){
        removeFile($app->thirdImage->url, $dryRun);
        removeFile($app->thirdImage->thumb, $dryRun);
    }
    if(isset($app->carInfo) && isset($app->carInfo->plateImage)){
        removeFile($app->carInfo->plateImage, $dryRun);
    }
    // address is encrypted when loaded without owner session, so we derive
    // the map image path from contextImage->url: cdn2stg/2/4mYJ5a2bkuDR,co.jpg → cdn2stg/2/4mYJ5a2bkuDR,ma.png
    if (isset($app->contextImage->url)) {
        removeFile(strstr($app->contextImage->url, ',', true) . ',ma.png', $dryRun);
    }

    echo " zgłoszenie oraz jego pliki usunięte;\n\n";
    if($dryRun){
        return;
    }
    \store\delete('applications', $app->id);
}

function removeFile($fileName, $dryRun){
    if(!isset($fileName) || empty($fileName)){
        return;
    }
    $allowedBase = realpath(ROOT . 'cdn2');
    $file = realpath(ROOT . $fileName);
    if (!$file || !$allowedBase || !str_starts_with($file, $allowedBase . DIRECTORY_SEPARATOR)) {
        echo " ! '$fileName' poza dozwolonym katalogiem cdn2\n";
        return;
    }
    if(file_exists($file)){
        if(filetype($file) !== 'file'){
            echo " ! '$fileName' nie jest plikiem\n";
            return;
        }
        if(!$dryRun) {
            unlink($file);
            \storage\delete($fileName);
            echo " - $fileName usunięty lokalnie, usunięty z S3\n";
        } else {
            echo " - $fileName (do usunięcia lokalnie + S3)\n";
        }
    } else {
        if(!$dryRun) {
            \storage\delete($fileName);
            echo " - $fileName nie istniał lokalnie, usunięty z S3\n";
        } else {
            echo " - $fileName (nie istnieje lokalnie, do usunięcia z S3)\n";
        }
    }
}

function rmdirRecursive(string $dir): void {
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? rmdirRecursive($path) : unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
    }
    rmdir($dir);
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
 * Marks stuck sending apps as failed
 */
function cleanupStuckSendingApps($olderThanMinutes = 10, $dryRun = true) {
    global $interrupt;
    echo "Szukam zgłoszeń utkniętych w statusie 'sending' (starszych niż $olderThanMinutes min)...\n";
    $apps = getAllApplicationsByStatus('sending');

    $threshold = time() - ($olderThanMinutes * 60);

    foreach ($apps as $app) {
        if ($interrupt) exit;
        
        $lastStatusChange = null;
        if (isset($app->statusHistory) && !empty((array)$app->statusHistory)) {
            $timestamps = array_keys((array)$app->statusHistory);
            rsort($timestamps);
            $lastStatusChange = strtotime($timestamps[0]);
        }

        if (!$lastStatusChange) {
            // Fallback to 'added' if statusHistory is missing or empty
            $lastStatusChange = isset($app->added) ? strtotime($app->added) : null;
        }

        if ($lastStatusChange && $lastStatusChange < $threshold) {
            echo " - Markowanie zgłoszenia {$app->id} jako 'sending-failed' (ostatnia zmiana statusu: " . date('Y-m-d H:i:s', $lastStatusChange) . ")\n";
            if (!$dryRun) {
                $app->setStatus('sending-failed', true);
                \app\save($app);
            }
        }
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
        where json_extract(value, '$.status') not in ('archived', 'ready', 'draft', 'confirmed')
            and plateId || ' v2' in (select key from recydywa where json_extract(value, '$.appsCnt') > 1)
        group by 1
        order by 3 desc, 2 desc
    SQL;

    $stmt = \store\prepare($sql);
    $stmt->execute();

    while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
        if ($interrupt) exit;
        echo "{$row[0]} set\n";
        sleep(1);
        \recydywa\set($row[0], Recydywa::withValues($row[1], $row[2]));
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

    \semaphore\acquire($appId, "webhook:" . $mailEvent->name);

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
        logger("mailgun webhook error, Application $appId was not sent!", true);
        $application->sent = new \JSONObject();
        $application->sent->date = date(DT_FORMAT);
        $application->sent->subject = $payload['message']['headers']['subject'];
        $application->sent->to = $payload['message']['headers']['to'];
        $application->sent->cc = "({$application->email})";
        $application->sent->from = "uprzejmiedonosze.net (" . MAILER_FROM . ")";
        $application->sent->body = "Mailgun webhook error, was not sent field was not saved, thus email body is missing!";
        $application->sent->method = "MailGun";
    }

    $comment = $mailEvent->formatComment($application->email);
    if ($comment) $application->addComment("mailer", $comment, $mailEvent->status);
    $ccToUser = $application->email == $recipient;

    if ($recipient == MAILER_FROM) {
        // this is BCC to Uprzejmie Donoszę, ignore it
        \webhook\mark($id, 'bcc to ud@, ignoring');
        \semaphore\release($appId, "webhook:" . $mailEvent->name);
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
    \semaphore\release($appId, "webhook:" . $mailEvent->name);
    $application->syncToS3();

    if ($mailEvent->status == 'failed' && !$ccToUser)
        (new \MailGun())->notifyUser($application,
            "Nie udało się nam dostarczyć zgłoszenia {$application->getNumber()}",
            $mailEvent->getReason(),
            $recipient);

    echo "OK";
}

function processWebhooks(): void {
    $ids = \webhook\getUnprocessed();
    foreach ($ids as $id) {
        processWebhook($id);
    }
}


/**
 * Generates gallery images (clear + pixelated) for all applications that have
 * a contextImage but no galleryReady flag yet. Safe to run multiple times.
 */
function migrateGalleryImages(bool $dryRun=true): void {
    global $interrupt;

    $batch = 1;
    do {
        if ($interrupt) exit;
        echo "\nBatch $batch — szukam zgłoszeń bez gallery...\n";

        $sql = <<<SQL
            SELECT key, value, email
            FROM applications
            WHERE json_extract(value, '$.contextImage.thumb') IS NOT NULL
              AND json_extract(value, '$.contextImage.galleryReady') IS NULL
            LIMIT 1000;
        SQL;

        $stmt = \store\prepare($sql);
        $stmt->execute();

        $apps = [];
        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            $apps[$row[0]] = Application::withJson($row[1], $row[2]);
        }

        foreach ($apps as $app) {
            if ($interrupt) exit;
            echo "  - {$app->id} ({$app->contextImage->thumb})\n";
            if (!$dryRun) {
                $app->generateGalleryImages();
                if ($app->contextImage->galleryReady ?? false) {
                    \app\markGalleryReady($app->id, $app->carInfo->plateId ?? null);
                } else {
                    echo "  ! skipped (storage not enabled or generation failed)\n";
                }
            }
        }

        echo "  Batch $batch: przetworzono " . count($apps) . " zgłoszeń.\n";
        $batch++;
    } while (count($apps) > 0);
}

/**
 * Removes local CDN files that are already on S3 with a matching size.
 * Files belonging to applications in draft/ready/confirmed status are skipped
 * (they may be actively edited and not yet fully synced).
 * Files missing from S3 are uploaded first, then removed locally.
 * Files with a size mismatch are reported but left intact.
 *
 * Usage: purgeLocalFiles(dryRun:false);
 */
function purgeLocalFiles(bool $dryRun=true): void {
    $cdnDir = ROOT . \storage\cdnPrefix();
    if (!is_dir($cdnDir)) {
        echo "Katalog $cdnDir nie istnieje — brak plików lokalnych.\n";
        return;
    }

    // Collect IDs of applications that are actively being worked on.
    $stmt = \store\prepare(<<<SQL
        SELECT key FROM applications
        WHERE json_extract(value, '$.status') IN ('draft', 'ready', 'confirmed')
    SQL);
    $stmt->execute();
    $activeIds = array_column($stmt->fetchAll(\PDO::FETCH_NUM), 0);
    $activeIds = array_flip($activeIds); // for O(1) lookup
    echo "Pomijam pliki należące do " . count($activeIds) . " aktywnych zgłoszeń (draft/ready/confirmed).\n\n";

    $deleted = $missing = $mismatch = $skipped = 0;

    $iter = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($cdnDir, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iter as $file) {
        if (!$file->isFile()) continue;

        // Compute S3 key relative to ROOT (e.g. "cdn2/15742/abc,ca.jpg")
        $localPath = $file->getPathname();
        $key = ltrim(substr($localPath, strlen(ROOT)), '/');

        // Extract app ID: filename up to first comma (e.g. "abc" from "abc,ca.jpg")
        $basename = $file->getBasename();
        $appId = strstr($basename, ',', before_needle: true) ?: $basename;

        if (isset($activeIds[$appId])) {
            echo " ~ $key — pominięty (aktywne zgłoszenie)\n";
            $skipped++;
            continue;
        }

        $localSize  = $file->getSize();
        $remoteSize = \storage\remote_size($key);

        if ($remoteSize === null) {
            if ($dryRun) {
                echo " ^ $key — brak na S3, do wysłania\n";
            } else {
                if (\storage\upload($localPath, $key)) {
                    echo " ^ $key ($localSize B) — wysłany do S3\n";
                } else {
                    echo " ! $key ($localSize B) — błąd wysyłania do S3\n";
                }
            }
            $missing++;
        } elseif ($remoteSize !== $localSize) {
            echo " ! $key — rozmiar niezgodny (lokalnie: $localSize B, S3: $remoteSize B)\n";
            $mismatch++;
        } else {
            if ($dryRun) {
                echo " - $key ($localSize B) — do usunięcia\n";
            } else {
                unlink($localPath);
                echo " - $key ($localSize B) — usunięty\n";
            }
            $deleted++;
        }
    }

    echo "\nPodsumowanie:";
    echo " usunięto=$deleted, wysłano_do_S3=$missing, pominięto=$skipped, niezgodny_rozmiar=$mismatch\n";
}

/**
 * Compares local CDN volume with S3. Never deletes anything.
 * - file local, brak na S3  → wysyła do S3 i raportuje
 * - plik lokalny OK         → cicho pomija
 * - rozmiar niezgodny       → raportuje
 * Applications in draft/ready/confirmed status are skipped.
 *
 * Usage: checkS3();
 */
function checkS3(): void {
    $cdnDir = ROOT . \storage\cdnPrefix();
    if (!is_dir($cdnDir)) {
        echo "Katalog $cdnDir nie istnieje — brak plików lokalnych.\n";
        return;
    }

    $stmt = \store\prepare(<<<SQL
        SELECT key FROM applications
        WHERE json_extract(value, '$.status') IN ('draft', 'ready', 'confirmed')
    SQL);
    $stmt->execute();
    $activeIds = array_flip(array_column($stmt->fetchAll(\PDO::FETCH_NUM), 0));
    echo "Pomijam pliki należące do " . count($activeIds) . " aktywnych zgłoszeń (draft/ready/confirmed).\n\n";

    $uploaded = $mismatch = $skipped = $ok = 0;

    $iter = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($cdnDir, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iter as $file) {
        if (!$file->isFile()) continue;

        $localPath = $file->getPathname();
        $key       = ltrim(substr($localPath, strlen(ROOT)), '/');
        $basename  = $file->getBasename();
        $appId     = strstr($basename, ',', before_needle: true) ?: $basename;

        if (isset($activeIds[$appId]) || $file->getExtension() === 'pdf') {
            $skipped++;
            continue;
        }

        $localSize  = $file->getSize();
        $remoteSize = \storage\remote_size($key);

        if ($remoteSize === null) {
            if (\storage\upload($localPath, $key)) {
                echo " ^ $key ($localSize B) — brakowało na S3, wysłany\n";
                $uploaded++;
            } else {
                echo " ! $key ($localSize B) — błąd wysyłania do S3\n";
            }
        } elseif ($remoteSize !== $localSize) {
            echo " ! $key — rozmiar niezgodny (lokalnie: $localSize B, S3: $remoteSize B)\n";
            $mismatch++;
        } else {
            $ok++;
        }
    }

    echo "\nPodsumowanie:";
    echo " ok=$ok, wysłano_do_S3=$uploaded, niezgodny_rozmiar=$mismatch, pominięto=$skipped\n";
}

