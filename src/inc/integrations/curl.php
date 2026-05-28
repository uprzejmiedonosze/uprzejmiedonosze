<?PHP namespace curl;

function request(string $url, array $params, string $vendor, array|null $headers=[], int $retries = 2): array|null {
    $ch = curl_init($url . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    #curl_setopt($ch, CURLOPT_REFERER, "https://agendaparkingowa.pl");
    curl_setopt($ch, CURLOPT_USERAGENT, "UprzejmieDonosze/1.0");

    array_push($headers, "Accept-Language: pl");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $output = null;
    for ($attempt = 0; $attempt <= $retries; $attempt++) {
        $output = curl_exec($ch);
        if (!curl_errno($ch)) {
            break;
        }
        if ($attempt < $retries) {
            \telemetry\log("api_retry", null, [
                "vendor" => $vendor,
                "attempt" => $attempt + 1,
                "error" => curl_error($ch)
            ]);
            usleep(500000); // 0.5s przerwy
        }
    }

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        \telemetry\log("api_error", null, [
            "vendor" => $vendor,
            "error" => $error,
            "url" => $url
        ]);
        logger("Nie udało się pobrać danych z $vendor po $retries próbach: $error");
        throw new \Exception("Problem z połączeniem z $vendor. Spróbuj ponownie za chwilę.", 503);
    }
    curl_close($ch);

    $json = json_decode($output, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logger("Parsowanie JSON z $vendor " . $output . " " . json_last_error_msg());
        throw new \Exception("Niezrozumiała odpowiedź z serwerów $vendor.", 503);
    }
    return $json;
}
