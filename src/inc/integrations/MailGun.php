<?php
require_once(__DIR__ . '/../PDFGenerator.php');

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use \JSONObject as JSONObject;
use Symfony\Component\Mime\Part\DataPart;

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class Mail extends CityAPI {
    public bool $withXls = false;

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    function send(&$application){
        parent::checkApplication($application);
        global $storage;

        $to = "szymon.nieradka@gmail.co";
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
        $message->text(parent::formatEmail($application, true));
        //$message->sender($application->user->email);
        $message->replyTo(new Address($application->user->email, $application->user->name));
        //$message->returnPath($application->user->email);
        
        $message->getHeaders()->addTextHeader("X-UD-AppId", $application->id);
        $message->getHeaders()->addTextHeader("X-UD-UserId", $application->getUserNumber());
        $message->getHeaders()->addTextHeader("X-UD-AppNumber", $application->getNumber());
        $message->getHeaders()->addTextHeader("v:appid", $application->id);
        $message->getHeaders()->addTextHeader("v:userid", $application->getUserNumber());
        $message->getHeaders()->addTextHeader("v:appnumber", $application->getNumber());
        $message->getHeaders()->addTextHeader('content-transfer-encoding', 'quoted-printable');

        $application->setStatus('confirmed-waiting');
        $application->addComment("admin", "Wysłano do {$application->guessSMData()->getName()}.");
        $application->sent = new JSONObject();
        $application->sent->date = date(DT_FORMAT);
        $application->sent->subject = $subject;
        $application->sent->to = $to;
        $application->sent->cc = "{$application->user->name} ({$application->user->email})";
        $application->sent->from = "uprzejmiedonosze.net (" . MAILER_FROM . ")";
        $application->sent->body = parent::formatEmail($application, false);
        $application->sent->method = "MailGun";

        [$fileatt, $fileattname] = application2PDF($application);

        $message->attachFromPath($fileatt, $fileattname);

        if ($this->withXls) {
            $message->addPart(new DataPart(
                XlsHandler::Application2Xls($application),
                $application->getAppXlsFilename(),
                "application/vnd.ms-excel"
            ));
        }

        try {
            if (!isDev())
                $mailer->send($message);
        } catch (TransportExceptionInterface $error) {
            throw new Exception($error, 500);
        }

        global $storage;
        $storage->saveApplication($application);
        return $application;
    }
}

?>