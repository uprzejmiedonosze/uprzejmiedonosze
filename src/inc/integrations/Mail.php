<?php
require_once(__DIR__ . '/../PDFGenerator.php');

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use \JSONObject as JSONObject;

class Mail extends CityAPI {

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    function send(&$application){
        parent::checkApplication($application);
        global $storage;

        $to = "szymon.nieradka@gmail.com";
        if(isProd()){
            $to = $application->guessSMData()->email;
        }
         
        $transport = Transport::fromDsn(SMTP_GMAIL . '://' . SMTP_USER . ':' . SMTP_PASS . '@' . SMTP_HOST . ':' . SMTP_PORT);
        $mailer = new Mailer($transport); 

        $subject = $application->getEmailSubject();

        $message = (new Email());
        $message->from(new Address(EMAIL_SENDER, 'uprzejmiedonosze.net'));
        $message->to($to);
        $message->subject($subject);
        $message->cc(new Address($application->user->email, $application->user->name));
        $message->text(parent::formatEmail($application, true));
        $message->sender($application->user->email);
        $message->replyTo(new Address($application->user->email, $application->user->name));
        $message->returnPath($application->user->email);
        
        $message->getHeaders()->addTextHeader("X-UD-AppId", $application->id);
        $message->getHeaders()->addTextHeader("X-UD-UserId", $application->getUserNumber());
        $message->getHeaders()->addTextHeader("X-UD-AppNumber", $application->getNumber());

        $messageId = $message->getHeaders()->get('Message-ID');

        $application->setStatus('confirmed-waiting');
        $application->addComment("admin", "Wysłano na adres {$application->guessSMData()->getName()} ($to).");
        $application->sent = new JSONObject();
        $application->sent->date = date(DT_FORMAT);
        $application->sent->subject = $subject;
        $application->sent->to = $to;
        $application->sent->cc = "{$application->user->name} ({$application->user->email})";
        $application->sent->from = "uprzejmiedonosze.net (" . EMAIL_SENDER . ")";
        $application->sent->body = parent::formatEmail($application, false);
        $application->sent->$messageId = $messageId;
        $application->sent->method = "Mail";

        [$fileatt, $fileattname] = application2PDF($application);

        $message->attachFromPath($fileatt, $fileattname);

        try {
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