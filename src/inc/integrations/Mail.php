<?php

class Mail extends CityAPI {
    function send(&$application){
        parent::checkApplication($application);
        global $storage;

        $to = "szymon.nieradka@gmail.com";
        if(isProd()){
            $to = $application->guessSMData()->email;
        }
        
        $transport = (new Swift_SmtpTransport(SMTP_HOST, SMTP_PORT, SMTP_SSL))
          ->setUsername(SMTP_USER)
          ->setPassword(SMTP_PASS);

        $mailer = new Swift_Mailer($transport);
        
        $subject = $application->getEmailSubject();
        $message = (new Swift_Message($subject))
          ->setFrom(EMAIL_SENDER, $application->user->name)
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
        $application->sentViaMail = new JSONObject();
        $application->sentViaMail->date = date(DT_FORMAT);
        $application->sentViaMail->subject = $subject;
        $application->sentViaMail->to = $to;
        $application->sentViaMail->cc = "{$application->user->name} ({$application->user->email})";
        $application->sentViaMail->from = "{$application->user->name} (" . SMTP_USER . ")";
        $application->sentViaMail->body = parent::formatEmail($application, false);
        $application->sentViaMail->$messageId = $messageId;

        require(__DIR__ . '/../PDFGenerator.php');
        [$fileatt, $fileattname] = application2PDF($application);

        $message->attach(Swift_Attachment::fromPath($fileatt)
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