<?php

class Poznan extends CityAPI {
    function send(&$application){
        parent::checkApplication($application);

        $url = "https://www.poznan.pl/mimtest/api/submit.html?service=fixmycity";
        if(isProd()){
            $url = "https://www.poznan.pl/mim/api/submit.html?service=fixmycity";
        }
        $data = array(
            'lat' => $application->getLat(),
            'lon' => $application->getLon(),
            'category' => '1118_9608', // "Zagrożenia w ruchu drogowym"
            'subcategory' => (($application->category == 6)?
                '17402': // Ruch drogowy - niszczenie zieleni
                '86808'  // Ruch drogowy - parkowanie
            ),
            'name' => $application->getFirstName(), //imię zgłaszającego, pole obowiązkowe do 128 znaków
            'surname' => $application->getLastName(), //nazwisko zgłaszającego, pole obowiązkowe do 128 znaków
            'email' => $application->user->email, //email użytkownika, pole obowiązkowe
            'subject' => $application->getEmailSubject(), //temat zgłoszenia, pole obowiązkowe do 256 znaków
            'text' => trim(
                preg_replace('/\s+/', ' ',
                    preg_replace('/;/', ',', parent::formatMessage($application, 4000))
                )
            ),
            'address' => $application->address->address, //adres, pole opcjonalne, do 256 znaków
            'key' => '85951ba0a63d1051a09659ea0a9d8391' //klucz aplikacji, pole obowiązkowe
        );
        $application->setStatus('confirmed-waiting');
        $output = parent::curlShellSend($url, $data, $application);

        if(isset($output['response']['error_msg'])){
            raiseError($output['response']['error_msg'], 500);
        }

        $reply = "{$output['response']['msg']} (instancja: {$output['response']['instance']}, id: {$output['response']['id']})";

        $application->setStatus('confirmed-sm');
        $application->addComment($application->guessSMData()->getName(), $reply);
        $application->sentViaAPI = $reply;
        global $storage;
        $storage->saveApplication($application);
        return 'confirmed-sm';
    }
}

?>