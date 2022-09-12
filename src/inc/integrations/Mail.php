<?php
use \JSONObject as JSONObject;
use \Swift_SmtpTransport as SmtpTransport;
use \Swift_Message as Message;
use \Swift_Mailer as Mailer;
use \Swift_Attachment as Attachment;

class Mail extends CityAPI {

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    function send(&$application){
        parent::checkApplication($application);
        global $storage;

        $to = "szymon.nieradka@gmail.com";
        if(isProd()){
            $to = $application->guessSMData()->email;
        }
        
        $transport = (new SmtpTransport(SMTP_HOST, SMTP_PORT, SMTP_SSL))
          ->setUsername(SMTP_USER)
          ->setPassword(SMTP_PASS);

        $mailer = new Mailer($transport);
        
        $subject = $application->getEmailSubject();
        $message = (new Message($subject))
            ->setFrom(EMAIL_SENDER, 'uprzejmiedonosze.net')
            ->setTo($to)
            ->addCc($application->user->email, $application->user->name)
            ->setBody(parent::formatEmail($application, true))
            ->setSender($application->user->email)
            ->setReplyTo($application->user->email, $application->user->name)
            ->setReturnPath($application->user->email);
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

        require(__DIR__ . '/../PDFGenerator.php');
        [$fileatt, $fileattname] = application2PDF($application);

        $message->attach(Attachment::fromPath($fileatt)
            ->setFilename($fileattname));

        $result = $mailer->send($message);
        if(!$result){
            raiseError($result, 500, true);
        }

        global $storage;
        $storage->saveApplication($application);
        return 'confirmed-waiting';
    }
}

?>