<?php

class Mail extends CityAPI {
    function send(&$application){
        parent::checkApplication($application);
        global $storage;

        $user = $storage->getCurrentUser();

        if(isProd()){
            $to      = $application->guessSMData()->email;
        } else {
            $to      = "szymon.nieradka@gmail.com";
        }
        
        $transport = (new Swift_SmtpTransport(SMTP_HOST, SMTP_PORT, SMTP_SSL))
          ->setUsername(SMTP_USER)
          ->setPassword(SMTP_PASS);

        $mailer = new Swift_Mailer($transport);

        require(__DIR__ . '/../PDFGenerator.php');
        [$fileatt, $fileattname] = application2PDF($application->id);

        $message = (new Swift_Message($application->getTitle()))
          ->setFrom([$application->user->email => $application->user->name])
          ->setTo([$to])
          ->addCc([$application->user->email => $application->user->name])
          ->setBody(parent::formatEmail($application))
          ->attach(Swift_Attachment::fromPath($fileatt)
            ->setFilename($fileattname))
          ->setSender($application->user->email)
          ->setReturnPath($application->user->email);

        $result = $mailer->send($message);
        if(!$result){
          raiseError($result, 500, true);
        }

        //$application->setStatus('confirmed-waiting');
        $application->addComment("admin", "Wysłano na adres {$application->guessSMData()->address[0]} ($to).");
        global $storage;
        $storage->saveApplication($application);
        return 'confirmed-waiting';
    }
}

?>