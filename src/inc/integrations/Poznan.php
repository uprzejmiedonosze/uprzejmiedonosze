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
    private function fallbackMessage(string $reason): string {
        $code = null;
        if (preg_match('/HTTP\\s*(\\d{3})/i', $reason, $matches)) {
            $code = $matches[1];
        }
        if ($code) {
            return "Z powodu problemu z API (kod {$code}) zgłoszenie wysłano e‑mailem.";
        }
        $lower = strtolower($reason);
        if (str_contains($lower, 'timeout')) {
            return "Z powodu przekroczenia czasu odpowiedzi API zgłoszenie wysłano e‑mailem.";
        }
        if (str_contains($lower, 'auth')) {
            return "Z powodu problemu z uwierzytelnieniem API zgłoszenie wysłano e‑mailem.";
        }
        return "Z powodu problemu z API zgłoszenie wysłano e‑mailem.";
    }

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
            $application->sent = new JSONObject();
            $application->setStatus('sending');

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
                if ($httpStatus !== 'unknown') {
                    $fallbackReason = "API returned HTTP " . (int)$httpStatus;
                } else {
                    $fallbackReason = $e->getMessage();
                }
                logger("SMMP_DEBUG delivery_failure appId={$application->id} reason=\"{$fallbackReason}\" fallback=email method=email", true);

                $application->setStatus('confirmed', true);
                $mail = new Mail();
                $application = $mail->send($application);
                if (isset($application->sent)) {
                    $application->sent->fallback_message = $this->fallbackMessage($fallbackReason);
                }
                \app\save($application);
            }
        } finally {
            \semaphore\release($application->id, "sendPoznan");
        }

        return $application;
    }
}

?>
