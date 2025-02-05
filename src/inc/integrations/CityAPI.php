<?php

use app\Application;
use \Exception as Exception;

abstract class CityAPI {
    abstract function send(Application $application);

    static function checkApplication(Application &$application){
        global $STATUSES;
        $status = $STATUSES[$application->status];
        if(!$status->sendable){
            throw new Exception("Nie mogę wysłać zgłoszenia '{$application->number}' w statusie '{$status->name}'", 403);
        }
        if(!$application->guessSMData(true)->api){
            throw new MissingSMException("Nie mogę wysłać zgłoszenia '{$application->number}' – brak przypisanej straży miejskiej");
        }
        return true;
    }

    function formatMessage(Application &$application, $limit = 10000){
        $twig = initBareTwig();
        $user = \user\current();
        $sex = ($user)? $user->getSex(): SEXSTRINGS['?'];
        return substr($twig->render('_application.txt.twig', [
            'app' => $application,
            'config' => [
                'sex' => $sex
            ]
        ]), 0, $limit);
    }

    function formatEmail(Application &$application, bool $withUserData) {
        $twig = initBareTwig();
        $user = \user\current();
        $sex = ($user)? $user->getSex(): SEXSTRINGS['?'];
        return $twig->render('_application.email.twig', [
            'app' => $application,
            'withUserData' => $withUserData,
            'config' => [
                'sex' => $sex
            ],
            'now' => date(DT_FORMAT)
        ]);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    function curlShellSend(string $url, &$data, Application &$application){
        $root = realpath('/var/www/%HOST%/');
        $contextImage = "$root/{$application->contextImage->url}";
        $carImage = "$root/{$application->carImage->url}";
        $json = json_encode($data);

        $thirdImageForm = '';
        if (isset($application->thirdImage->url)) {
            $thirdImage = "$root/{$application->thirdImage->url}";
            $thirdImageForm = '--form uz_file_1=@\\"' . $thirdImage . '\\" ';
        }

        $curl = "curl -s --location --request POST '$url' "
            . "--header 'Authorization: Basic c3p5bW9uQG5pZXJhZGthLm5ldDplaUYmb29xdWVlN0Y=' "
            . "--header 'Content-Type: multipart/form-data' "
            . "--form 'json={$json}' "
            . '--form uz_file=@\\"' . $contextImage . '\\" '
            . '--form uz_file_0=@\\"' . $carImage . '\\" '
            . $thirdImageForm;
        
        $response = exec("$curl 2>&1", $retArr, $retVal);

        if($retVal !== 0){
            $error = "Błąd komunikacji z API {$application->address->city}: retVal=$retVal, response=$response";
        }

        $json = json_decode($response, true);
        if(!json_last_error() === JSON_ERROR_NONE){
            $error = "Błąd komunikacji z API {$application->address->city}: json_last_error=" . json_last_error_msg();
        }

        if (is_null($json)) {
            $error = "Błąd komunikacji z API {$application->address->city}: empty-json, response=" . print_r($response, true);
        }

        if(isset($error)){
            logger($response, true);
            logger($curl, true);
            throw new Exception($error, 500);
        }

        $application->sent->curl = $json;
        return $json;
    }

}

require(__DIR__ . '/Poznan.php');
require(__DIR__ . '/Mail.php');
require(__DIR__ . '/MailGun.php');

?>