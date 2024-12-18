<?php
require_once(__DIR__ . '/../PDFGenerator.php');

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
        $message->cc(new Address($application->user->email, $application->user->name));
        $message->bcc(new Address(MAILER_FROM, 'uprzejmiedonosze.net'));
        $message->text(parent::formatEmail($application, true));
        $message->replyTo(new Address($application->user->email, $application->user->name));
        
        $message->getHeaders()->addTextHeader("v:appid", $application->id);
        $message->getHeaders()->addTextHeader("v:userid", $application->getUserNumber());
        $message->getHeaders()->addTextHeader("v:appnumber", $application->getNumber());
        $message->getHeaders()->addTextHeader("v:isprod", isProd() ? 1 : 0);
        $message->getHeaders()->addTextHeader("o:tag", $application->address->city ?? '-no-city');
        $message->getHeaders()->addTextHeader("o:testmode", isDev());
        $message->getHeaders()->addTextHeader('content-transfer-encoding', 'quoted-printable');

        $application->setStatus('sending');
        $application->sent = new JSONObject();
        $application->sent->date = date(DT_FORMAT);
        $application->sent->subject = $subject;
        $application->sent->to = $to;
        $application->sent->cc = "{$application->user->name} ({$application->user->email})";
        $application->sent->from = "uprzejmiedonosze.net (" . MAILER_FROM . ")";
        $application->sent->body = parent::formatEmail($application, false);
        $application->sent->method = "MailGun";

        [$fileatt, $fileattname] = application2PDF($application);

        logger("Sending message {$application->id} with MailGun, sent->to {$application->sent->to}", true);

        $message->attachFromPath($fileatt, $fileattname);

        if ($this->withXls) {
            $message->addPart(new DataPart(
                XlsHandler::Application2Xls($application),
                $application->getAppXlsFilename(),
                "application/vnd.ms-excel"
            ));
        }

        try {
            $mailer->send($message);
        } catch (TransportExceptionInterface $error) {
            $application->sent->error = $error->getMessage();
            $application->setStatus('sending-problem', true);
            throw new Exception($error, 500);
        }

        return \app\save($application);
    }

    function notifyUser(app\Application &$application, string $subject, string $reason){
        $messageBody = initBareTwig()->render('_notification.email.twig', [
            'app' => $application,
            'reason' => $reason,
            'sm' => $application->guessSMData()->getShortName()
        ]);
        $transport = Transport::fromDsn(MAILER_DSN);
        $mailer = new Mailer($transport); 
        $message = (new Email());
        $message->from(new Address(MAILER_FROM, 'uprzejmiedonosze.net'));
        $message->to(new Address($application->user->email, $application->user->name));
        $message->cc(new Address(MAILER_FROM, 'uprzejmiedonosze.net'));
        $message->subject($subject);
        $message->text($messageBody);
        
        $message->getHeaders()->addTextHeader("v:appid", $application->id);
        $message->getHeaders()->addTextHeader("v:userid", $application->getUserNumber());
        $message->getHeaders()->addTextHeader("v:appnumber", $application->getNumber());
        $message->getHeaders()->addTextHeader("v:nofitication", true);
        $message->getHeaders()->addTextHeader('content-transfer-encoding', 'quoted-printable');

        try {
            $mailer->send($message);
        } catch (TransportExceptionInterface $error) {
            throw new Exception($error, 500);
        }
    }

}

?>