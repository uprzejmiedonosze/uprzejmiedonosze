<?PHP namespace admin;

require(__DIR__ . '/../../vendor/autoload.php');
require(__DIR__ . '/../inc/include.php');
require(__DIR__ . '/../inc/store/Admin.php');
require_once(__DIR__ . '/common.php');

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

$dryRun = in_array('--dry-run', $argv ?? []);

if ($dryRun) {
    print("\n=== DRY RUN — brak zmian w bazie ani wysyłki maili ===\n");
}

sendWarning($dryRun);
send2ndWarning($dryRun);
removeUsers($dryRun);

function sendWarning(bool $dryRun) {
    $candidates = getInactiveUsers();
    print("\n[sendWarning] kandydatów: " . count($candidates));

    foreach ($candidates as $user) {
        print("\nsendWarning " . $user->getEmail());
        __sendWarning($user, $dryRun);
    }
}

function send2ndWarning(bool $dryRun) {
    $candidates = getWarnedUsers();
    print("\n[send2ndWarning] kandydatów: " . count($candidates));

    foreach ($candidates as $user) {
        print("\nsend2ndWarning " . $user->getEmail());
        __send2ndWarning($user, $dryRun);
    }
}

function removeUsers(bool $dryRun) {
    $users = getUsersToRemove();
    print("\n[removeUsers] kandydatów: " . count($users));

    foreach ($users as $user) {
        print("\nremoveUsers " . $user->getEmail());
        __remove($user, $dryRun);
    }
}

function __sendWarning(\user\User $user, bool $dryRun) {
    $subject = "Twoje konto w Uprzejmie Donoszę zostanie usunięte z powodu nieaktywności";
    $text = "Cześć,

Twoje ostatnie zgłoszenie nieprawidłowego parkowania w aplikacji Uprzejmie Donoszę ma ponad rok. Zgodnie z naszą polityką bezpieczeństwa, konta nieaktywne przez ponad 12 miesięcy są usuwane.

Jeśli chcesz zachować swoje konto, potwierdź aktualny regulamin w ciągu najbliższych 2 miesięcy:
https://uprzejmiedonosze.net/register.html?update

Jeśli nie podejmiesz żadnej akcji, Twoje konto zostanie trwale usunięte — wraz ze wszystkimi zgłoszeniami i zdjęciami.

Jeśli masz pytania dotyczące bezpieczeństwa swoich danych, zapraszam do zapoznania się z naszą polityką prywatności:
https://uprzejmiedonosze.net/bezpieczenstwo.html

Pozdrawiam,
Szymon Nieradka
uprzejmiedonosze.net";

    __sendEmail($user, $subject, $text, $dryRun);
    if (!$dryRun) {
        $user->removalWarningSent = date(DT_FORMAT);
        \user\save($user, dontDecode:true);
    }
}


function __send2ndWarning(\user\User $user, bool $dryRun) {
    $subject = "Ostatnie ostrzeżenie — Twoje konto w Uprzejmie Donoszę zostanie usunięte za 2 tygodnie";
    $text = "Cześć,

Dwa miesiące temu poinformowaliśmy Cię o planowanym usunięciu Twojego konta w Uprzejmie Donoszę z powodu nieaktywności. To jest ostatnie przypomnienie.

Jeśli chcesz zachować konto, potwierdź aktualny regulamin w ciągu najbliższych 2 tygodni:
https://uprzejmiedonosze.net/register.html?update

Po usunięciu konta:
1. Twoje dane osobowe, zgłoszenia i zdjęcia zostaną trwale usunięte z naszych serwerów.
2. Ewentualna ponowna rejestracja utworzy nowe konto z czystą historią.

Pozdrawiam,
Szymon Nieradka
uprzejmiedonosze.net";

    __sendEmail($user, $subject, $text, $dryRun);
    if (!$dryRun) {
        $user->removal2ndWarningSent = date(DT_FORMAT);
        \user\save($user, dontDecode:true);
    }
}

function __remove(\user\User $user, bool $dryRun) {
    removeUser($user->getEmail(), dryRun:$dryRun);

    if (!$dryRun) {
        $subject = "Twoje konto w Uprzejmie Donoszę zostało usunięte";
        $text = "Cześć,

Zgodnie z wcześniejszymi informacjami, Twoje konto w serwisie Uprzejmie Donoszę zostało usunięte z powodu długiej nieaktywności.

Co to oznacza:
1. Twoje dane osobowe, zgłoszenia i zdjęcia zostały usunięte z naszych serwerów.
2. Kompletne usunięcie danych z backupów i logów nastąpi w ciągu 14 dni (okres retencji).
3. Jeśli chcesz, możesz założyć nowe konto logując się ponownie na uprzejmiedonosze.net.

Pozdrawiam,
Szymon Nieradka
uprzejmiedonosze.net";

        __sendEmail($user, $subject, $text, $dryRun);
    }
}

function __sendEmail(\user\User $user, $subject, $text, bool $dryRun) {

    $message = (new Email());
    $message->from(new Address(MAILER_FROM, 'uprzejmiedonosze.net'));
    $message->to($user->getEmail());

    $message->subject($subject);
    $message->text($text);

    $message->getHeaders()->addTextHeader("v:isprod", isProd() ? 1 : 0);
    $message->getHeaders()->addTextHeader("v:environment", environment());
    $message->getHeaders()->addTextHeader("o:testmode", isDev());
    $message->getHeaders()->addTextHeader("References", "removal-warning-{$user->number}@dka.email");
    $message->getHeaders()->addTextHeader("X-Entity-Ref-ID", "removal-warning-{$user->number}");
    $message->getHeaders()->addTextHeader('content-transfer-encoding', 'quoted-printable');

    if ($dryRun) {
        print("\n  [email] To: {$user->getEmail()} | Subject: $subject\n");
        return;
    }

    $mailer = new Mailer(Transport::fromDsn(MAILER_DSN));
    $mailer->send($message);
}
