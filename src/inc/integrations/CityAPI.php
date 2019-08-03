<?php

abstract class CityAPI {
    abstract function send($application);

    function checkApplication($application){
        if($application->status !== 'confirmed'){
            throw new Exception("Próba wysłania zgłoszenia $application->id w statusie $application->status.");
        }
        if(!$application->guessSMData()->api){
            throw new Exception("Próba wysłania zgłoszenia $application->id dla miasta "
               . $application->guessSMData()->city);
        }
        return true;
    }

    function formatMessage($application, $limit = 10000){
        return substr(generate('_application.txt.twig', [
            'app' => $application
        ]), 0, $limit);
    }

    function curlSend($url, $auth, $data, $application){
        $curl = curl_init();
        $root = realpath('/var/www/%HOST%/');

        $postFields = array(
            'json' => json_encode($data),
            'uz_file' => [
                new CURLFile("$root/" . $application->contextImage->url, "image/jpg", $application->getAppImageFilenamePrefix() . '-kontekst.jpg'),
                new CURLFile("$root/" . $application->carImage->url, "image/jpg", $application->getAppImageFilenamePrefix() . '-tablica.jpg')
            ]);

        print_r($postFields);

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => http_build_query($postFields),
            CURLOPT_HTTPHEADER => array($auth),
            CURLOPT_SAFE_UPLOAD => true
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            echo "cURL Error #:" . $err;
        } else {
            echo $response;
        }
    }
}

require(__DIR__ . '/Poznan.php');

?>