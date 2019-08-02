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
        return substr(
            "W dniu {$application->getDate()} {$application->getDateTimeDivider()} {$application->getTime()} " . 
            "{$application->guessUserSex()['bylam']} świadkiem pozostawienia pojazdu o nr rejestracyjnym " . 
            "{$application->carInfo->plateId} pod adresem {$application->address->address}. " . 
            "{$application->getCategory()[1]} Sytuacja jest widoczna na załączonych zdjęciach. " . 
            "Zdjęcia {$application->guessUserSex()['wykonalam']} samodzielnie. {$application->getJSONSafeComment()}\n\n" . 
            ((!$application->statements || !$application->statements->witness)? "Nie {$application->guessUserSex()['bylam']} świadkiem samego momentu parkowania. " : " " ) .
            "Jestem {$application->guessUserSex()['swiadoma']} odpowiedzialności karnej z art. 233 §1–§3 Kodeksu karnego " .
            "oraz treści art. 182, 183 i 185 Kodeksu Postępowania Karnego.\n\n" .
            ((!$application->user->exposeData)? "Równocześnie proszę o niezamieszczanie w protokole danych dotyczących mojego miejsca zamieszkania.": "") .
            "\n\nAdres URL zawierający szczegóły zgłoszenia: %HTTPS%://%HOST%/ud-{$application->id}.html", 0, $limit);
    }
}

require(__DIR__ . '/Poznan.php');

?>