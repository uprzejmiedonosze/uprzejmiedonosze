<?php
require_once(__DIR__ . '/../converters/index.php');

use app\Application;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use \JSONObject as JSONObject;


/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class MailGun extends CityAPI {
    public bool $withXls = false;
    public bool $alternate = false;

    public function __construct(bool $alternate = false) {
        $this->alternate = $alternate;
    }


    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    function send(Application $application) {
        parent::checkApplication($application);

        $to = "szymon.nieradka@gmail.com";
        if (isProd()) {
            $to = $application->guessSMData()->email;
        }

        $subject = $application->getEmailSubject();

        $message = (new Email());
        if ($this->alternate) {
            $message->from(new Address(MAILER_FROM_ALTER, 'uprzejmiedonosze.net'));
        } else {
            $message->from(new Address(MAILER_FROM, 'uprzejmiedonosze.net'));
        }

        $message->to($to);
        $message->subject($subject);
        $message->cc(new Address($application->email, $application->user->name));
        $message->bcc(new Address(MAILER_FROM, 'uprzejmiedonosze.net'));
        $message->text(parent::formatEmail($application, true));
        $message->replyTo(new Address($application->email, $application->user->name));

        $message->getHeaders()->addTextHeader("v:appid", $application->id);
        $message->getHeaders()->addTextHeader("v:userid", $application->getUserNumber());
        $message->getHeaders()->addTextHeader("v:appnumber", $application->getNumber());
        $message->getHeaders()->addTextHeader("v:isprod", isProd() ? 1 : 0);
        $message->getHeaders()->addTextHeader("v:environment", environment());
        $message->getHeaders()->addTextHeader("o:tag", $application->address->city ?? '-no-city');
        $message->getHeaders()->addTextHeader("o:testmode", isDev());
        $message->getHeaders()->addTextHeader("References", $application->id . "@dka.email");
        $message->getHeaders()->addTextHeader("X-Entity-Ref-ID", $application->id);
        $message->getHeaders()->addTextHeader('content-transfer-encoding', 'quoted-printable');

        try {
            \semaphore\acquire($application->id, "sendMailGun");
            $application = \app\get($application->id); // get the latest version of the application
            parent::checkApplication($application);

            [$fileatt, $fileattname] = \app\toPdf($application);
            $message->attachFromPath($fileatt, $fileattname);
            [$fileatt, $fileattname] = \app\toZip($application);
            $message->attachFromPath($fileatt, $fileattname);

            $application->setStatus('sending');
            $application->sent = new JSONObject();
            $application->sent->date = date(DT_FORMAT);
            $application->sent->subject = $subject;
            $application->sent->to = $to;
            $application->sent->cc = "{$application->user->name} ({$application->email})";
            $application->sent->from = "uprzejmiedonosze.net (" . MAILER_FROM . ")";
            $application->sent->body = parent::formatEmail($application, false);
            $application->sent->method = "MailGun";
            \app\save($application);

            $dsn = $this->alternate ? MAILER_DSN_ALTER : MAILER_DSN;
            $dryRun = isDev() && $dsn === '';
            if (!$dryRun) {
                $transport = Transport::fromDsn($dsn);
                $mailer = new Mailer($transport);
                $mailer->send($message);
            }

            if (isDev()) {
                logger("Sending app {$application->id} with MailGun" . ($dryRun ? ' (dry-run)' : ''));
                $application->setStatus('confirmed-waiting');
                logger("Marking app {$application->id} as confirmed-waiting (sent)");
                \app\save($application);
            }
            \telemetry\log('api_email', null, ['status' => 'success']);
        } catch (\Throwable $error) {
            \telemetry\log('api_email', null, ['status' => 'error']);
            $msg = "Sending email {$application->id} with MailGun, exception: " . $error->getMessage();
            logger($msg, true);
            \telemetry\log('app_error', $application->id, ['msg' => $msg, 'source' => 'MailGun::send']);
            $application->setStatus('sending-failed', true);
            unset($application->sent);
            \app\save($application);
            if ($error instanceof \Exception) {
                throw $error;
            }
            throw new \Exception($error->getMessage(), 500, $error);
        } finally {
            \semaphore\release($application->id, "sendMailGun");
            \app\rmPdf($application);
            \app\rmZip($application);
        }
        return $application;
    }

    function notifyUser(app\Application &$application, string $subject, string $reason, string $recipient){
        $messageBody = initBareTwig()->render('_notification.email.twig', [
            'BASE_URL' => BASE_URL,
            'app' => $application,
            'reason' => $reason,
            'recipient' => $recipient
        ]);
        $transport = Transport::fromDsn(MAILER_DSN);
        $mailer = new Mailer($transport);
        $message = (new Email());
        $message->from(new Address(MAILER_FROM, 'uprzejmiedonosze.net'));
        $message->to(new Address($application->email));
        $message->cc(new Address('ud@uprzejmiedonosze.net', 'Uprzejmie Donoszę'));
        $message->subject($subject);
        $message->text($messageBody);

        $message->getHeaders()->addTextHeader("v:appid", $application->id);
        $message->getHeaders()->addTextHeader("v:appnumber", $application->getNumber());
        $message->getHeaders()->addTextHeader("v:isprod", isProd() ? 1 : 0);
        $message->getHeaders()->addTextHeader("v:environment", environment());
        $message->getHeaders()->addTextHeader("v:nofitication", true);
        $message->getHeaders()->addTextHeader("o:tag", $application->smCity ?? '-no-city');
        $message->getHeaders()->addTextHeader("o:testmode", isDev());
        $message->getHeaders()->addTextHeader("References", $application->id . "@dka.email");
        $message->getHeaders()->addTextHeader("X-Entity-Ref-ID", $application->id);
        $message->getHeaders()->addTextHeader('content-transfer-encoding', 'quoted-printable');

        try {
            $mailer->send($message);
            \telemetry\log('api_email', null, ['status' => 'success']);
        } catch (TransportExceptionInterface $error) {
            \telemetry\log('api_email', null, ['status' => 'error']);
            throw new Exception($error, 500);
        }
    }
}

?>
