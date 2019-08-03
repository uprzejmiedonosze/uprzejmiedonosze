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
}

require(__DIR__ . '/Poznan.php');

?>