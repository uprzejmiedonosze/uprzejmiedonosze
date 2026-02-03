<?php

use app\Application;

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class Poznan extends CityAPI {
    /*
     * FixMyCity (Poznań) delivery:
     * - Best-effort API first; if it fails, we fall back to email.
     */
    private function apiUrl(): string {
        if (isProd()) {
            return "https://www.poznan.pl/mim/api/submit.html?service=fixmycity";
        }
        return "https://www.poznan.pl/mimtest/api/submit.html?service=fixmycity";
    }

    function send(Application $application){
        parent::checkApplication($application);

        $url = $this->apiUrl();
        $data = array(
            'lat' => $application->address->lat,
            'lon' => $application->address->lng,
            'category' => '1118_9608', // "Zagrożenia w ruchu drogowym"
            'subcategory' => (($application->category == 6)?
                '17402': // Ruch drogowy - niszczenie zieleni
                '86808'  // Ruch drogowy - parkowanie
            ),
            'name' => $application->getFirstName(), //imię zgłaszającego, pole obowiązkowe do 128 znaków
            'surname' => $application->getLastName(), //nazwisko zgłaszającego, pole obowiązkowe do 128 znaków
            'email' => $application->email, //e-mail użytkownika, pole obowiązkowe
            'subject' => $application->getEmailSubject(), //temat zgłoszenia, pole obowiązkowe do 256 znaków
            'text' => cleanWhiteChars(
                preg_replace('/;/', ',', parent::formatMessage($application, 4000))
            ),
            'address' => $application->address->address, //adres, pole opcjonalne, do 256 znaków
            'key' => '85951ba0a63d1051a09659ea0a9d8391' //klucz aplikacji, pole obowiązkowe
        );

        try {
            \semaphore\acquire($application->id, "sendPoznan");
            $application = \app\get($application->id); // get the latest version of the application
            $application->setStatus('confirmed-waiting');
            $application->sent = new JSONObject();

            try {
                $output = parent::curlShellSend($url, $data, $application);

                unset($application->sent->curl_raw, $application->sent->curl_http_status);

                if(isset($output['response']['error_msg'])){
                    throw new Exception($output['response']['error_msg'], 500);
                }

                $reply = "{$output['response']['msg']} (instancja: {$output['response']['instance']}, id: {$output['response']['id']})";

                $application->setStatus('confirmed-sm');
                $application->addComment($application->guessSMData()->getName(), $reply);
                $application->sent->date = date(DT_FORMAT);
                $application->sent->reply = $reply;
                $application->sent->subject = $application->getEmailSubject();
                $application->sent->to = "fixmycity";
                $application->sent->method = "Poznan";

                \app\save($application);
            } catch (\Throwable $e) {
                $httpStatus = $application->sent->curl_http_status ?? 'unknown';
                $reason = $httpStatus !== 'unknown' ? "http_status={$httpStatus}" : "error=" . $e->getMessage();
                logger("SMMP_DEBUG delivery_failure appId={$application->id} {$reason} fallback=email method=email_fallback", true);

                $mail = new Mail();
                $application = $mail->send($application);
            }
        } finally {
            \semaphore\release($application->id, "sendPoznan");
        }

        return $application;
    }
}

?>
