<?php
require_once(__DIR__ . '/../converters/index.php');

use app\Application;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use \JSONObject as JSONObject;


/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class MailGun extends CityAPI {
    public bool $withXls = false;

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    function send(Application &$application){
        parent::checkApplication($application);

        $to = "szymon.nieradka@gmail.com";
        if(isProd()){
            $to = $application->guessSMData()->email;
        }
         
        $transport = Transport::fromDsn(MAILER_DSN);
        $mailer = new Mailer($transport); 

        $subject = $application->getEmailSubject();

        $message = (new Email());
        $message->from(new Address(MAILER_FROM, 'uprzejmiedonosze.net'));
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
            $userNumber = $application->user->number;
            $semaphore = \semaphore\acquire(intval($userNumber));
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

            logger("Sending email {$application->id} with MailGun, sent->to {$application->sent->to}");
            [$fileatt, $fileattname] = \app\toPdf($application);
            $message->attachFromPath($fileatt, $fileattname);
            [$fileatt, $fileattname] = \app\toZip($application);
            $message->attachFromPath($fileatt, $fileattname);

            if ($this->withXls) {
                $message->addPart(new DataPart(
                    \app\app2Xls($application),
                    $application->getAppFilename('.xls'),
                    "application/vnd.ms-excel"
                ));
            }

            $mailer->send($message);
            logger("Sending email {$application->id} with MailGun, sent");
        } catch (TransportExceptionInterface $error) {
            logger("Sending email {$application->id} with MailGun, exception" . $error->getMessage(), true);
            $application->setStatus('sending-failed', true);
            unset($application->sent);
            \app\save($application);
            throw new Exception($error->getMessage(), 500, $error);
        } finally {
            \semaphore\release($semaphore);
            \app\rmPdf($application);
            \app\rmZip($application);
        }
        return $application;
    }

    function notifyUser(app\Application &$application, string $subject, string $reason, string $recipient){
        $messageBody = initBareTwig()->render('_notification.email.twig', [
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
        } catch (TransportExceptionInterface $error) {
            throw new Exception($error, 500);
        }
    }
}

class MailGunWithXls extends Mail {
    public function __construct() {
        $this->withXls = true;
    }
}

?>