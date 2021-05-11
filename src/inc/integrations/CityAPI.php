<?php
use \Exception as Exception;

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

    function formatEmail(&$application, $withUserData = null){
        return generate('_application.email.twig', [
            'app' => $application, 
            'config' => [ 'isAppOwnerOrAdmin' => $withUserData ]
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    function curlShellSend($url, &$data, &$application){
        $root = realpath('/var/www/%HOST%/');
        $contextImage = "$root/{$application->contextImage->url}";
        $carImage = "$root/{$application->carImage->url}";
        $json = json_encode($data);

        $curl = "curl -s --location --request POST '$url' "
            . "--header 'Authorization: Basic c3p5bW9uQG5pZXJhZGthLm5ldDplaUYmb29xdWVlN0Y=' "
            . "--header 'Content-Type: multipart/form-data' "
            . "--form 'json={$json}' "
            . '--form uz_file=@\\"' . $contextImage . '\\" '
            . '--form uz_file_0=@\\"' . $carImage . '\\"';
        
        $response = exec("$curl 2>&1", $retArr, $retVal);

        if($retVal !== 0){
            $error = "Błąd komunikacji z API {$application->address->city}: $response";
        }

        $json = json_decode($response, true);
        if(!json_last_error() === JSON_ERROR_NONE){
            $error = "Błąd komunikacji z API {$application->address->city}: " . json_last_error_msg();
        }
        logger($curl, true);
        if(isset($error)){
            logger($response, true);
            raiseError($error, 500);
        }

        // zaznaczam na wszelki wypadek, ale poszczególne implementacje powinny to nadpisać
        $application->sentViaAPI = $json;
        return $json;
    }

}

require(__DIR__ . '/Poznan.php');
require(__DIR__ . '/Mail.php');

?>