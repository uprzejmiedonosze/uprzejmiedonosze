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
        \telemetry\log('api_googlemaps', null, ['status' => 'success']);
        return $result;
    }
    \telemetry\log('api_googlemaps', null, ['status' => 'error']);
    if ($json['status'] == 'ZERO_RESULTS') {
        throw new \Exception("Brak wyników z serwerów Google Maps dla $lat,$lng: " . json_encode($json), 404);
    }
    throw new \Exception("Niepoprawna odpowiedź z serwerów Google Maps.", 503);
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
    if (!$json) {
        try {
            $json = \curl\request($url, $params, "Nominatim");
        } catch (\Throwable $e) {
            \telemetry\log('api_nominatim', null, ['status' => 'error']);
            throw $e;
        }
    }

    if (!$json || !isset($json['address'])) {
        \telemetry\log('api_nominatim', null, ['status' => 'error']);
        throw new \Exception("Brak wyników z serwerów OpenStreetMap dla $lat,$lng " . json_encode($json), 404);
    }
    \telemetry\log('api_nominatim', null, ['status' => 'success']);

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

    $address['lat'] = $lat; // needed by StopAgresji::guess()
    $address['lng'] = $lng; // needed by StopAgresji::guess()

    \cache\geo\set(Type::Nominatim, "$lat,$lng", $json);

    global $SM_ADDRESSES;
    $smKey = \SM::guess((object)$address);
    return array(
        'address' => $address,
        'sm' => $SM_ADDRESSES[$smKey] ?? $SM_ADDRESSES['_nieznane'],
        'sa' => \SM::resolve(\StopAgresji::guess((object)$address), true)
    );
}

/**
 * Forward-geocodes a free-text address to [lat, lng] via Nominatim search
 * (country-restricted to Poland). Returns null when nothing unambiguous is
 * found — callers should treat that as "display string only", never as an
 * error. Only the coordinates are returned; feed them back into Nominatim()
 * for the structured address fields.
 *
 * Requires a locality in the query (a comma, e.g. "street, city") — a bare
 * street/number without a city is exactly the ambiguous case Nominatim will
 * happily match to the wrong town, so it's rejected before ever calling out.
 */
function NominatimSearch(string $query): ?array {
    $query = trim($query);
    if ($query === '' || !str_contains($query, ',')) {
        return null;
    }
    $cacheKey = 'search:' . mb_strtolower($query);
    $cached = \cache\geo\get(Type::Nominatim, $cacheKey);
    if ($cached) {
        return $cached['miss'] ?? false ? null : $cached;
    }

    $params = array(
        "q" => $query,
        "format" => 'jsonv2',
        "addressdetails" => 0,
        "countrycodes" => 'pl',
        "limit" => 1
    );
    $url = "https://nominatim.openstreetmap.org/search?";

    try {
        $json = \curl\request($url, $params, "Nominatim");
    } catch (\Throwable $e) {
        \telemetry\log('api_nominatim', null, ['status' => 'error']);
        throw $e;
    }

    $first = is_array($json) ? reset($json) : false;
    if (!$first || !isset($first['lat'], $first['lon'])) {
        // Not an API error — Nominatim answered fine, it just found nothing.
        // Cache the miss (shorter TTL than a hit) so a typo'd or unmappable
        // address doesn't re-hit the API on every retry.
        \telemetry\log('api_nominatim', null, ['status' => 'not_found']);
        \cache\set(Type::Nominatim, $cacheKey, ['miss' => true], MEMCACHE_COMPRESSED, 6 * 60 * 60);
        return null;
    }
    \telemetry\log('api_nominatim', null, ['status' => 'success']);

    $result = ['lat' => (float)$first['lat'], 'lng' => (float)$first['lon']];
    \cache\geo\set(Type::Nominatim, $cacheKey, $result);
    return $result;
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
    try {
        $json = \curl\request($url, $params, "MapBox");
    } catch (\Throwable $e) {
        \telemetry\log('api_mapbox', null, ['status' => 'error']);
        throw $e;
    }

    if (!$json || !isset($json['features']) || sizeof($json['features']) == 0) {
        \telemetry\log('api_mapbox', null, ['status' => 'error']);
        throw new \Exception("Brak wyników z serwerów MapBox dla $lat,$lng " . json_encode($json), 404);
    }
    \telemetry\log('api_mapbox', null, ['status' => 'success']);
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

function normalizeLatLng(float|string $lat, float|string $lng): string {
    return normalizeGeo($lat) . "," . normalizeGeo($lng);
}

/**
 * Reads GPS coordinates [lat, lng] from a JPEG/PNG's EXIF headers — the
 * server-side mirror of the web client's client-side EXIF GPS extraction
 * (src/js/new-app/images.js). Returns null when the image carries no GPS
 * data, or when the exif extension isn't available.
 */
function exifGps(string $imageBytes): ?array {
    if (!function_exists('exif_read_data')) {
        logger('exifGps: PHP exif extension not available');
        return null;
    }
    // exif_read_data() only accepts a path; stage the raw bytes (the GD
    // pipeline strips EXIF on re-encode, so it must be read pre-upload).
    $tmp = tempnam(sys_get_temp_dir(), 'ud-exif');
    if ($tmp === false) {
        return null;
    }
    try {
        if (file_put_contents($tmp, $imageBytes) === false) {
            return null;
        }
        $exif = @\exif_read_data($tmp, 'GPS', true, false);
    } catch (\Throwable $e) {
        return null;
    } finally {
        @unlink($tmp); // nosemgrep: php.lang.security.unlink-use.unlink-use — $tmp is a tempnam() path, not user input
    }
    if (!is_array($exif) || !isset($exif['GPS'])) {
        return null;
    }

    $lat = exifGpsComponent($exif['GPS']['GPSLatitude'] ?? null, $exif['GPS']['GPSLatitudeRef'] ?? null, true);
    $lng = exifGpsComponent($exif['GPS']['GPSLongitude'] ?? null, $exif['GPS']['GPSLongitudeRef'] ?? null, false);
    if ($lat === null || $lng === null) {
        return null;
    }
    return [$lat, $lng];
}

function exifGpsComponent($components, $ref, bool $isLat): ?float {
    if (!is_array($components) || count($components) < 3) {
        return null;
    }
    $degrees = exifRational($components[0] ?? null);
    $minutes = exifRational($components[1] ?? null);
    $seconds = exifRational($components[2] ?? null);
    if ($degrees === null || $minutes === null || $seconds === null) {
        return null;
    }
    $value = $degrees + $minutes / 60 + $seconds / 3600;
    $negative = $isLat ? strtoupper((string)$ref) === 'S' : strtoupper((string)$ref) === 'W';
    return $negative ? -$value : $value;
}

function exifRational($component): ?float {
    if (is_array($component)) {
        $num = $component[0] ?? null;
        $den = $component[1] ?? null;
    } elseif (is_string($component) && str_contains($component, '/')) {
        [$num, $den] = explode('/', $component, 2);
    } else {
        return is_numeric($component) ? (float)$component : null;
    }
    if (!is_numeric($num) || !is_numeric($den) || (float)$den == 0) {
        return null;
    }
    return (float)$num / (float)$den;
}
