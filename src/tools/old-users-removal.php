<?PHP namespace admin;

require(__DIR__ . '/../../vendor/autoload.php');
require(__DIR__ . '/../inc/include.php');
require(__DIR__ . '/../inc/store/Admin.php');

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

sendWarning();
send2ndWarning();
removeUsers();

function sendWarning() {
    $candidates = getInactiveUsers();

    foreach ($candidates as $user) {
        print("\nsendWarning" . $user->getEmail());
        __sendWarning($user);
    }
}

function send2ndWarning() {
    $candidates = getWarnedUsers();

    foreach ($candidates as $user) {
        print("\nsend2ndWarning" . $user->getEmail());
        __send2ndWarning($user);
    }
}

function removeUsers() {
    $users = getUsersToRemove();

    foreach ($users as $user) {
        print("\nremoveUsers" . $user->getEmail());
        __remove($user);
    }


}

function __sendWarning(\user\User $user) {
    $subject = "Twoje konto w Uprzejmie Donoszę zostanie usunięte po 2 latach nieaktywności";
    $text = "
Cześć,

Twoje ostatnie zgłoszenie nieprawidłowego parkowania ma ponad dwa lata. Nie chcemy przechowywać twoich danych w nieskończoność, dlatego po tak długiej nieaktywności twoje konto zostanie usunięte.

Jeśli chcesz temu zapobiec musisz w przeciągu najbliższego miesiąca potwierdzić aktualny regulamin na stronie https://uprzejmiedonosze.net/register.html?update

Jeśli masz obawy o bezpieczeństwo swojego konta, to przeczytaj koniecznie to jak szyfrujemy wrażliwe dane na stronie:

...

Szymon Nieradka
    ";

    __sendEmail($user, $subject, $text);
    $user->removalWarningSent = date(DT_FORMAT);
    \user\save($user, dontDecode:true);
}


function __send2ndWarning(\user\User $user) {
    $dryRun = removeUser($user->getEmail(), dryRun:true);
    $subject = "Twoje konto w Uprzejmie Donoszę zostanie usunięte za 2 tygodnie";
    $text = "
Cześć,

Dwa miesiące temu wysłaliśmy Ci informację o usunięciu twojego konta na Uprzejmie Donoszę ze względu na długą nieaktywność. To jest ostatnie ostrzeżenie przed usunięciem konta.

Jeśli chcesz temu zapobiec musisz w przeciągu najbliższych 2 tygodni potwierdzić aktualny regulamin na stronie https://uprzejmiedonosze.net/register.html?update

Usunięcie konta będzie oznaczać, że:

1. Na naszych serwerach nie będzie twoich danych osobowych ani szczegółów wykonanych przez Ciebie zgłoszeń (np. zdjęć).
2. Kolejna próba zalogowania się stworzy nowe konto z czystą historią.

Pełen zapis plików oraz danych jakie zostaną usunięte za dwa tygodnie znajduje się na końcu wiadomości.

Szymon Nieradka

Symulacja usunięcia konta:
$dryRun
    ";

    __sendEmail($user, $subject, $text);
    $user->removal2ndWarningSent = date(DT_FORMAT);
    \user\save($user, dontDecode:true);
}

function __remove(\user\User $user) {
    $removalLog = removeUser($user->getEmail(), dryRun:false);

    $subject = "Twoje konto w Uprzejmie Donoszę zostało usunięte";
    $text = "
Cześć,

Dwa miesiące temu wysłaliśmy Ci informację o planowaniu usunięciu Twojego konta na Uprzejmie Donoszę ze względu na długą nieaktywność. Potem ostatnie ostrzeżenie. Ten email jest już tylko potwierdzeniem, że usunęliśmy już Twoje konto z serwisu. Co to oznacza?

1. Na naszych serwerach nie ma twoich danych osobowych ani szczegółów wykonanych przez Ciebie zgłoszeń (np. zdjęć).
2. Kolejna próba zalogowania się stworzy nowe konto z czystą historią.

Pełen zapis plików oraz danych jakie zostały usunięte na końcu wiadomości. Kompletne usunięcie twoich danych, w tym logów, backupów oraz kopii wiadomości email nastąpi w przeciągu kolejnych dwóch tygodni (retencja danych).

Szymon Nieradka

$removalLog
    ";

    __sendEmail($user, $subject, $text);
}

function __sendEmail(\user\User $user, $subject, $text) {

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

    $transport = Transport::fromDsn(MAILER_DSN);

    // @TODO
    echo "\nSubject: $subject\n";
    echo "To: {$user->getEmail()}\n";
    echo "From: " . MAILER_FROM . "\n";
    echo $text;
    
    //$mailer = new Mailer($transport);
    //$mailer->send($message);
}