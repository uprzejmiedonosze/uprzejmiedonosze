<?PHP namespace curl;

function request(string $url, array $params, string $vendor, array|null $headers=[]): array|null {
    $ch = curl_init($url . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_REFERER, "https://uprzejmiedonosze.net");

    array_push($headers, "Accept-Language: pl");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $output = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        logger("Nie udało się pobrać danych z $vendor: $error");
        throw new \Exception("Nie udało się pobrać odpowiedzi z serwerów $vendor: $error", 500);
    }
    curl_close($ch);

    $json = json_decode($output, true);
    if (!json_last_error() === JSON_ERROR_NONE) {
        logger("Parsowanie JSON z $vendor " . $output . " " . json_last_error_msg());
        throw new \Exception("Bełkotliwa odpowiedź z serwerów $vendor: $output", 500);
    }
    return $json;
}
