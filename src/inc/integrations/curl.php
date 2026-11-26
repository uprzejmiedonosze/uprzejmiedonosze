<?PHP namespace curl;

/** The vendor answered with something that isn't JSON (e.g. a CDN error page). */
class BadResponseException extends \Exception {}

/** The vendor refused the call because of too many requests. */
class RateLimitedException extends BadResponseException {}

/** 429 is the standard answer; OSM's CDN also sends 629 when it sheds our traffic. */
function isRateLimited(int $status): bool {
    return $status === 429 || $status === 629;
}

function request(string $url, array $params, string $vendor, array|null $headers=[], int $retries = 2, int $timeout = 0): array|null {
    $ch = curl_init($url . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($timeout > 0) {
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    }
    #curl_setopt($ch, CURLOPT_REFERER, "https://agendaparkingowa.pl");
    curl_setopt($ch, CURLOPT_USERAGENT, "UprzejmieDonosze/1.0 (+https://uprzejmiedonosze.net)");

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
        log_info("Nie udało się pobrać danych z $vendor po $retries próbach: $error", true);
        throw new \Exception("Problem z połączeniem z $vendor. Spróbuj ponownie za chwilę.", 503);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (isRateLimited($status)) {
        \telemetry\log("api_error", null, [
            "vendor" => $vendor,
            "error" => "HTTP $status",
            "url" => $url
        ]);
        log_info("$vendor odrzuca zapytania: HTTP $status", true);
        throw new RateLimitedException("Serwery $vendor są przeciążone. Spróbuj ponownie za chwilę.", 503);
    }

    $json = json_decode($output, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $excerpt = mb_substr(preg_replace('/\s+/', ' ', (string)$output), 0, 200);
        log_info("Parsowanie JSON z $vendor (HTTP $status): " . json_last_error_msg() . " $excerpt", true);
        throw new BadResponseException("Niezrozumiała odpowiedź z serwerów $vendor.", 503);
    }
    return $json;
}
