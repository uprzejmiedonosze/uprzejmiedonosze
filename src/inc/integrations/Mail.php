<?php

class Mail extends CityAPI {
    function send(&$application){
        parent::checkApplication($application);
        global $storage;

        $user = $storage->getCurrentUser();

        if('%HOST%' == 'uprzejmiedonosze.net'){
            $to      = $application->guessSMData()->email;
        } else {
            $to      = "szymon.nieradka@gmail.com";
        }
        
        $subject     = $application->getTitle();
        $mainMessage = "Dzień dobry,\n\nW załączeniu znajduje się zgłoszenie nieprawidłowego parkowania. Wszystkie przypadki albo wpływały na bezpieczeństwo pieszych, albo wiązały się z niszczeniem mienia.\n\nPozdrawiam,";
        $fileatt     = "/var/www/uprzejmiedonosze.net/cdn2/3/Zgloszenia-Szczecin-Szymon-Nieradka-2020-01-31.pdf";
        $fileatttype = "application/pdf";
        $fileattname = "Zgloszenia-Szczecin-Szymon-Nieradka-2020-01-31.pdf";
        
        $headers     = "From: {$application->user->name} <{$application->user->email}>\r\n";
        $headers    .= "Reply-To: {$application->user->email}\r\n";
        $headers    .= "Cc: {$application->user->name} <{$application->user->email}>";
        
        // File
        $file = fopen($fileatt, 'rb');
        $data = fread($file, filesize($fileatt));
        fclose($file);
        
        // This attaches the file
        $semi_rand     = md5(time());
        $mime_boundary = "==Multipart_Boundary_x{$semi_rand}x";
        $headers      .= "\nMIME-Version: 1.0\r\n" .
          "Content-Type: multipart/mixed;\r\n" .
          " boundary=\"{$mime_boundary}\"";
        
        $message = "This is a multi-part message in MIME format.\r\n\r\n" .
          "--{$mime_boundary}\r\n" .
          "Content-type: text/plain; charset=\"UTF-8\"; format=flowed \r\n" .
          "Mime-Version: 1.0 \r\n" .
          "Content-Transfer-Encoding: quoted-printable \r\n\r\n" .
          quoted_printable_encode($mainMessage) . "\r\n";
        
        $data = chunk_split(base64_encode($data));
        $message .= "--{$mime_boundary}\r\n" .
          "Content-Type: {$fileatttype};\r\n" .
          " name=\"{$fileattname}\"\r\n" .
          "Content-Disposition: attachment;\r\n" .
          " filename=\"{$fileattname}\"\r\n" .
          "Content-Transfer-Encoding: base64\r\n\\rn" .
        $data . "\n\n" .
         "--{$mime_boundary}--\n";
        
        // Send the email
        if(!mail($to, $subject, $message, $headers)) {
            raiseError(error_get_last()['message'], 500);
        }

        $application->setStatus('confirmed-waiting');
        $application->addComment("admin", "Wysłano na adres {$application->guessSMData()->address[0]} ($to).");
        global $storage;
        $storage->saveApplication($application);
        return 'confirmed-waiting';
    }
}

?>