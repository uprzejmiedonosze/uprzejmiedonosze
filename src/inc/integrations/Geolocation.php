<?PHP namespace geo;

require(__DIR__ . '/curl.php');

use cache\Type;

function GoogleMaps($lat, $lng) {
    $lat = normalizeGeo($lat);
    $lng = normalizeGeo($lng);
    $result = \cache\geo\get(Type::GoogleMaps, "$lat,$lng");
    if ($result) return $result;

    $params = array(
        "latlng" => "$lat,$lng",
        "key" => GOOGLE_MAPS_API_TOKEN,
        "language" => "pl",
        "result_type" => "street_address"
    );
    $url = "https://maps.googleapis.com/maps/api/geocode/json?";
    $json = \curl\request($url, $params, "Google Maps");

    if ($json['status'] == 'OK' && $json['results']) {
        $result = $json['results'][0];
        \cache\geo\set(Type::GoogleMaps, "$lat,$lng", $result);
        return $result;
    }
    if ($json['status'] == 'ZERO_RESULTS') {
        throw new \Exception("Brak wyników z serwerów Google Maps dla $lat,$lng: " . json_encode($json), 404);
    }
    throw new \Exception("Niepoprawna odpowiedź z serwerów Google Maps: " . json_encode($json), 500);
}

function Nominatim(float $lat, float $lng): array {
    $lat = normalizeGeo($lat);
    $lng = normalizeGeo($lng);
    $params = array(
        "lat" => $lat,
        "lon" => $lng,
        "format" => 'jsonv2',
        "addressdetails" => 1
    );
    $url = "https://nominatim.openstreetmap.org/reverse?";

    $json = \cache\geo\get(Type::Nominatim, "$lat,$lng");
    if (!$json) $json = \curl\request($url, $params, "Nominatim");

    if (!$json || !isset($json['address'])) {
        throw new \Exception("Brak wyników z serwerów OpenStreetMap dla $lat,$lng " . json_encode($json), 404);
    }

    $address = $json['address'];

    if ($address["country_code"] !== "pl") {
        throw new \Exception("Poza granicami kraju OpenStreetMap dla $lat,$lng {$address['country_code']}", 404);
    }

    $address['voivodeship'] = str_replace("województwo ", "", $address['state'] ?? "");
    unset($address['state']);

    $address['district'] = $address['suburb'] ?? $address['borough'] ?? $address['quarter'] ?? $address['neighbourhood'] ?? '';

    $address['city'] = $address['city'] ?? $address['town'] ?? $address['village'] ?? null;

    $county = $address['county'] ?? (($address['city']) ? "gmina {$address['city']}" : null);
    $municipality = $address['municipality'] ?? (($address['city']) ? "powiat {$address['city']}" : null);

    // nominantim can replace county and municipality...
    if (str_starts_with($county, 'powiat'))
        $address['municipality'] = $county;
    if (str_starts_with($municipality, 'powiat'))
        $address['municipality'] = $municipality;

    if (str_starts_with($county, 'gmina'))
        $address['county'] = $county;
    if (str_starts_with($municipality, 'gmina'))
        $address['county'] = $municipality;

    $address['address'] = trim(($address['road'] ?? '') . " " . ($address['house_number'] ?? '')) . ", " . ($address['city'] ?? '');

    global $SM_ADDRESSES;
    global $STOP_AGRESJI;

    $address['lat'] = $lat; // needed by StopAgresji::guess()
    $address['lng'] = $lng; // needed by StopAgresji::guess()

    \cache\geo\set(Type::Nominatim, "$lat,$lng", $json);

    return array(
        'address' => $address,
        'sm' => $SM_ADDRESSES[\SM::guess((object)$address)],
        'sa' => $STOP_AGRESJI[\StopAgresji::guess((object)$address)]
    );
}

function MapBox(float $lat, float $lng): array {
    $lat = normalizeGeo($lat);
    $lng = normalizeGeo($lng);
    $properties = \cache\geo\get(Type::MapBox, "$lat,$lng");
    if ($properties) return $properties;

    $params = array(
        "country" => 'pl',
        "limit" => 1,
        "types" => 'address,place,district,postcode,region,neighborhood',
        "language" => 'pl',
        "longitude" => $lng,
        "latitude" => $lat,
        "access_token" => MAPBOX_API_TOKEN
    );
    $url = "https://api.mapbox.com/search/geocode/v6/reverse?";
    $json = \curl\request($url, $params, "MapBox");

    if (!$json || !isset($json['features']) || sizeof($json['features']) == 0) {
        throw new \Exception("Brak wyników z serwerów MapBox dla $lat,$lng " . json_encode($json), 404);
    }
    $properties = reset($json['features'])['properties'];
    $properties['address'] = array();
    array_walk($properties['context'], function ($val, $key) use (&$properties) {
        $properties['address'][$key] = $val['name'];
    });

    $properties['address']['voivodeship'] = str_replace("województwo ", "", $properties['address']["region"] ?? "");
    unset($properties['address']['region']);

    $properties['address']['city'] = $properties['address']['place'] ?? "";
    unset($properties['address']['place']);

    $properties['name'] = fixRomanNumerals($properties['name']);

    $properties['address']['address'] = ($properties['address']['city']) ? "{$properties['name']}, {$properties['address']['city']}" : $properties['name'];

    unset($properties['coordinates']);
    unset($properties['bbox']);
    unset($properties['context']);

    \cache\geo\set(Type::MapBox, "$lat,$lng", $properties);
    return $properties;
}

function normalizeGeo(float|string $geo): string {
    return sprintf('%.4F', $geo);
}

function normalizeLatLng($lat, $lng) {
    return normalizeGeo($lat) . "," . normalizeGeo($lng);
}
