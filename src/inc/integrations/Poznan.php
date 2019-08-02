<?php

class Poznan extends CityAPI {
    function send($application){
        parent::checkApplication($application);

        $data = array(
            'lat' => $application->getLat(),
            'lon' => $application->getLon(),
            'category' => '1', //obowiązkowy identyfikator kategorii zgłoszenia
            'subcategory' => '2', //obowiązkowy identyfikator podkategorii zgłoszenia
            'name' => $application->getFirstName(), //imię zgłaszającego, pole obowiązkowe do 128 znaków
            'surname' => $application->getLastName(), //nazwisko zgłaszającego, pole obowiązkowe do ,128 znaków
            'email' => $application->user->email, //email użytkownika, pole obowiązkowe
            'subject' => $application->getTitle(), //temat zgłoszenia, pole obowiązkowe do 256 znaków
            'text' => parent::formatMessage($application, 4000),
            'address' => $application->address->address, //adres, pole opcjonalne, do 256 znaków
            'key' => '85951ba0a63d1051a09659ea0a9d8391' //klucz aplikacji, pole obowiązkowe
        );
    }
}

?>