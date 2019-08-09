<?php

abstract class CityAPI {
    abstract function send(&$application);

    function checkApplication(&$application){
        if($application->status !== 'confirmed'){
            throw new Exception("Próba wysłania zgłoszenia $application->id w statusie $application->status.");
        }
        if(!$application->guessSMData()->api){
            throw new Exception("Próba wysłania zgłoszenia $application->id dla miasta "
               . $application->guessSMData()->city);
        }
        return true;
    }

    function formatMessage(&$application, $limit = 10000){
        return substr(generate('_application.txt.twig', [
            'app' => $application
        ]), 0, $limit);
    }

    function curlSend($url, $auth, &$data, &$application){
        $curl = curl_init();
        $root = realpath('/var/www/%HOST%/');

        $postFields = array(
            'json' => json_encode($data),
            'uz_file' => [
                new CURLFile("$root/" . $application->contextImage->url, "image/jpg", $application->getAppImageFilenamePrefix() . '-kontekst.jpg'),
                new CURLFile("$root/" . $application->carImage->url, "image/jpg", $application->getAppImageFilenamePrefix() . '-tablica.jpg')
            ]);

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

        if(curl_errno($curl)){
            $error = "Błąd komunikacji z API {$sm->api}: " . curl_error($curl);
            curl_close($curl);
            raiseError($error, 500);
        }
        curl_close($curl);

        $json = @json_decode($response, true);
        if(!json_last_error() === JSON_ERROR_NONE){
            $error = "Błąd komunikacji z API {$sm->api}: " . json_last_error_msg();
            raiseError($error, 500);
        }

        $application->sentViaAPI = $json;

        return $json;

    }
}

require(__DIR__ . '/Poznan.php');

?>