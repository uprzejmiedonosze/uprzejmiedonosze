<?php

class Poznan extends CityAPI {
    function send(&$application){
        parent::checkApplication($application);

        if('%HOST%' == 'uprzejmiedonosze.net'){
            $url = "https://www.um.poznan.pl/mim/public/api/submit.html?service=fixmycity";
        }else{
            $url = "https://www.um.poznan.pl/mimtest/public/api/submit.html?service=fixmycity";
        }
        $auth = "Authorization: Basic c3p5bW9uQG5pZXJhZGthLm5ldDplaUYmb29xdWVlN0Y="; // tylko do testowego API
        $data = array(
            'lat' => $application->getLat(),
            'lon' => $application->getLon(),
            'category' => '1118_9608', // "Zagrożenia w ruchu drogowym"
            'subcategory' => (($application->category == 6)?
                '17402': // Ruch drogowy - niszczenie zieleni
                '86808'  // Ruch drogowy - parkowanie
            ),
            'name' => $application->getFirstName(), //imię zgłaszającego, pole obowiązkowe do 128 znaków
            'surname' => $application->getLastName(), //nazwisko zgłaszającego, pole obowiązkowe do ,128 znaków
            'email' => $application->user->email, //email użytkownika, pole obowiązkowe
            // @TODO usunąć odwołania do BETY
            'subject' => 'TEST - proszę zignorować zgłoszenie - ' . $application->getTitle(), //temat zgłoszenia, pole obowiązkowe do 256 znaków
            'text' => parent::formatMessage($application, 4000),
            'address' => $application->address->address, //adres, pole opcjonalne, do 256 znaków
            'key' => '85951ba0a63d1051a09659ea0a9d8391' //klucz aplikacji, pole obowiązkowe
        );
        $application->setStatus('confirmed');
        $output = parent::curlSend($url, $auth, $data, $application);

        $reply = "{$output['response']['msg']} (instancja: {$output['response']['instance']}, id: {$output['response']['id']})";

        $application->setStatus('confirmed-sm');
        $application->addComment($application->guessSMData()->address[0], $reply);
        $application->sentViaAPI = $reply;
        global $storage;
        $storage->saveApplication($application);
    }
}

?>