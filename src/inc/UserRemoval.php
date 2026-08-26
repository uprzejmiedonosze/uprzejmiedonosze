<?PHP namespace admin;

// Account-deletion logic shared by the retention cron (src/tools/old-users-removal.php)
// and the self-service "Skasuj konto" flow (UserHandler::deleteAccount). Split out of
// src/tools/common.php because that file isn't safe to require() from a web request —
// it registers a shutdown handler and POSIX signal handlers meant for a long-running CLI
// job, and pulls in WebhooksHandler.php for unrelated tooling.

use app\Application;
use user\User;

/**
 * Shared footer for every retention/removal e-mail (warnings + farewell, cron and
 * self-service). Starts with the standard "-- " signature-delimiter (dash-dash-space)
 * mail clients use to strip signatures from quoted replies — the trailing space matters.
 */
const SIGNATURE = "-- \nPozdrawiam,\n\nSzymon Nieradka\nuprzejmiedonosze.net – dbamy o chodniki\nzenfeed.eu – dbamy o zdrowie psychiczne\nagendaparkingowa.pl – konkretne postulaty";

/**
 * Removes user by given $email: all their applications (and files), their CDN folder,
 * every passkey and OAuth/MCP token, and anonymizes the user record in place (kept, under
 * a hashed key, so historical reports/telemetry referencing the old email don't break).
 */
function removeUser($email, $dryRun=true){
    if(!isset($email)){
        throw new \Exception("No email provided\n");
    }

    $email = \SQLite3::escapeString($email);
    $user = \user\get($email, dontDecode:true);
    $apps = \user\apps($user, 'allWithDrafts');

    __log("Usuwam wszystkie zgłoszenia użytkownika '$email'");
    foreach($apps as $app){
        removeApplication($app, $dryRun);
    }

    $cdn2UserFolder = ROOT . \storage\cdnPrefix() . "/{$user->number}/";
    if(file_exists($cdn2UserFolder) && filetype($cdn2UserFolder) == 'dir'){
        __log("Kasuję folder użytkownika");
        if(!$dryRun){
            rmdirRecursive($cdn2UserFolder);
        }
    }

    __log("Kasuję passkeye i połączenia MCP użytkownika");
    if(!$dryRun){
        \passkey\removeAllForEmail($email);
        \oauth\revokeAllForEmail($email);
    }

    __log("Zamazuję dane użytkownika w bazie");
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
    __log("Usuwam zgłoszenie numer $number [$status]$email$added");
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

    __log(" zgłoszenie oraz jego pliki usunięte;\n");
    if($dryRun){
        return;
    }
    \store\delete('applications', $app->id);
}

function removeFile($fileName, $dryRun){
    if(!isset($fileName) || empty($fileName)){
        return;
    }
    // Validate the containing directory, not the file itself: files are routinely
    // absent locally (syncToS3() uploads to B2 and unlinks the local copy), so
    // realpath() on $fileName would return false and reject every already-synced file.
    $allowedBase = realpath(ROOT . 'cdn2');
    $dir  = realpath(ROOT . dirname($fileName));
    $base = basename($fileName);
    $valid = $dir && $allowedBase && $base !== '' && $base !== '.' && $base !== '..'
        && str_starts_with($dir . DIRECTORY_SEPARATOR, $allowedBase . DIRECTORY_SEPARATOR);
    if (!$valid) {
        __log(" ! '$fileName' poza dozwolonym katalogiem cdn2");
        return;
    }
    $file = $dir . DIRECTORY_SEPARATOR . $base;
    if(file_exists($file)){
        if(filetype($file) !== 'file'){
            __log(" ! '$fileName' nie jest plikiem");
            return;
        }
        if(!$dryRun) {
            unlink($file);
            \storage\delete($fileName);
            __log(" - $fileName usunięty lokalnie, usunięty z S3");
        } else {
            __log(" - $fileName (do usunięcia lokalnie + S3)");
        }
    } else {
        if(!$dryRun) {
            \storage\delete($fileName);
            __log(" - $fileName nie istniał lokalnie, usunięty z S3");
        } else {
            __log(" - $fileName (nie istnieje lokalnie, do usunięcia z S3)");
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

// CLI (old-users-removal.php) prints progress straight to stdout; a web request must not
// leak the same lines into its HTML response, so it goes through logger() instead.
function __log(string $message): void {
    if (PHP_SAPI === 'cli') {
        print("\n$message");
        return;
    }
    logger($message);
}

/**
 * Sends one of the removal-flow emails (warning / last warning / farewell) and threads it
 * under the same message-id family so a mail client groups them together.
 */
function sendRemovalEmail(User $user, string $subject, string $text, bool $dryRun, string $refId): void {
    $message = (new \Symfony\Component\Mime\Email());
    $message->from(new \Symfony\Component\Mime\Address(MAILER_FROM, 'uprzejmiedonosze.net'));
    $message->to($user->getEmail());

    $message->subject($subject);
    $message->text($text);

    $message->getHeaders()->addTextHeader("v:isprod", isProd() ? 1 : 0);
    $message->getHeaders()->addTextHeader("v:environment", environment());
    $message->getHeaders()->addTextHeader("o:testmode", isDev());
    $message->getHeaders()->addTextHeader("References", "$refId@dka.email");
    $message->getHeaders()->addTextHeader("X-Entity-Ref-ID", $refId);
    $message->getHeaders()->addTextHeader('content-transfer-encoding', 'quoted-printable');

    if ($dryRun) {
        __log("[email] To: {$user->getEmail()} | Subject: $subject\n");
        return;
    }

    $mailer = new \Symfony\Component\Mailer\Mailer(\Symfony\Component\Mailer\Transport::fromDsn(MAILER_DSN));
    $mailer->send($message);
}

/**
 * The "your account has been deleted" email, sent both by the retention cron (after the
 * two warnings go unanswered) and by the self-service deletion flow. Only the opening
 * sentence differs — the cron's copy blames inactivity, which would be false for a
 * deletion the user asked for themselves — everything else (the numbered consequences)
 * is the exact copy from the existing retention flow, reused verbatim.
 */
function farewellEmail(User $user, bool $selfService, bool $dryRun): void {
    $subject = "Twoje konto w Uprzejmie Donoszę zostało usunięte";

    $intro = $selfService
        ? "Na Twoją prośbę Twoje konto w serwisie Uprzejmie Donoszę zostało usunięte."
        : "Zgodnie z wcześniejszymi informacjami, Twoje konto w serwisie Uprzejmie Donoszę zostało usunięte z powodu długiej nieaktywności.";

    $text = "Cześć,

$intro

Co to oznacza:
1. Twoje dane osobowe, zgłoszenia i zdjęcia zostały usunięte z naszych serwerów.
2. Kompletne usunięcie danych z backupów i logów nastąpi w ciągu 14 dni (okres retencji).
3. Jeśli chcesz, możesz założyć nowe konto logując się ponownie na uprzejmiedonosze.net.

" . SIGNATURE;

    $refId = $selfService ? "account-removed-{$user->number}" : "removal-warning-{$user->number}";
    sendRemovalEmail($user, $subject, $text, $dryRun, $refId);
}
