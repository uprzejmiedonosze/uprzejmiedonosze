<?PHP namespace geo;

require_once(__DIR__ . '/curl.php');

use cache\Type;

/** Seconds without any outbound Nominatim call after it refused one. */
const NOMINATIM_COOLDOWN = 60;
const NOMINATIM_COOLDOWN_KEY = 'cooldown';

/**
 * Every outbound Nominatim call goes through here: once OSM refuses one (rate
 * limit or an error page), further calls are skipped for NOMINATIM_COOLDOWN
 * seconds.
 */
function nominatimRequest(string $endpoint, array $params): ?array {
    if (\cache\get(Type::Nominatim, NOMINATIM_COOLDOWN_KEY)) {
        throw new \Exception("Serwery Nominatim są przeciążone. Spróbuj ponownie za chwilę.", 503);
    }
    try {
        return \curl\request("https://nominatim.openstreetmap.org/$endpoint?", $params, "Nominatim");
    } catch (\curl\BadResponseException $e) {
        \cache\set(Type::Nominatim, NOMINATIM_COOLDOWN_KEY, true, 0, NOMINATIM_COOLDOWN);
        throw $e;
    }
}

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

    $json = \cache\geo\get(Type::Nominatim, "$lat,$lng");
    if (!$json) {
        try {
            $json = nominatimRequest('reverse', $params);
        } catch (\Throwable $e) {
            \telemetry\log('api_nominatim', null, ['status' => 'error']);
            return fromMapBox($lat, $lng) ?? throw $e;
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

    if (needsAdminLookup($address))
        $address = withAdminBoundaries($address, NominatimAdmin($lat, $lng));

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

    return withUnits($address);
}

/** Response of Nominatim(): the address plus both possible recipients. */
function withUnits(array $address): array {
    global $SM_ADDRESSES;
    $smKey = \SM::guess((object)$address);
    return array(
        'address' => $address,
        'sm' => $SM_ADDRESSES[$smKey] ?? $SM_ADDRESSES['_nieznane'],
        'sa' => \SM::resolve(\StopAgresji::guess((object)$address), true)
    );
}

/**
 * Stand-in for Nominatim() when Nominatim is unavailable, built from the MapBox
 * address (the web form asks MapBox for the same point anyway). Returns null if
 * MapBox fails too or its address is not enough to pick a unit — see ADR 0010.
 */
function fromMapBox(string $lat, string $lng): ?array {
    try {
        $address = addressFromMapBox(MapBox($lat, $lng)['address'], $lat, $lng);
    } catch (\Throwable $e) {
        log_info("MapBox fallback failed for $lat,$lng: {$e->getMessage()}");
        return null;
    }
    return isEnoughForUnit($address) ? withUnits($address) : null;
}

/**
 * False when no unit matches, and for Warsaw: its units are per district, and
 * without one SM::guess() falls back to the city-wide default.
 */
function isEnoughForUnit(array $address): bool {
    if (trimstr2lower($address['city'] ?? '') === 'warszawa' && empty($address['district'])) return false;
    return \SM::guess((object)$address) !== '_nieznane';
}

/**
 * MapBox address in the shape of a Nominatim() one. MapBox gives no gmina,
 * powiat or city district.
 */
function addressFromMapBox(array $mapBox, string $lat, string $lng): array {
    $address = [
        'address' => $mapBox['address'] ?? '',
        'city' => ($mapBox['city'] ?? '') ?: null,
        'voivodeship' => $mapBox['voivodeship'] ?? '',
        'district' => '',
        'lat' => $lat,
        'lng' => $lng
    ];
    if (!empty($mapBox['postcode'])) $address['postcode'] = $mapBox['postcode'];
    return $address;
}

/**
 * Gmina and powiat for a point, from Nominatim's polygon containment
 * (zoom=10). Plain reverse geocoding (zoom=18) returns the address of the
 * *nearest object*, which near a gmina border can sit on the other side of it
 * (e.g. OBI Bielany Wrocławskie resolved to Zabrodzie, gmina Kąty
 * Wrocławskie) — see ADR 0007. Returns null on any failure: callers then keep
 * the zoom=18 values.
 */
function NominatimAdmin(string $lat, string $lng): ?array {
    $cacheKey = "$lat,$lng,zoom10";
    $json = \cache\geo\get(Type::Nominatim, $cacheKey);
    if (!$json) {
        try {
            $json = nominatimRequest('reverse', array(
                "lat" => $lat,
                "lon" => $lng,
                "format" => 'jsonv2',
                "addressdetails" => 1,
                "zoom" => 10
            ));
        } catch (\Throwable $e) {
            log_info("Nominatim zoom=10 failed for $lat,$lng: {$e->getMessage()}");
            return null;
        }
        if (!$json || !isset($json['address']))
            return null;
        \cache\geo\set(Type::Nominatim, $cacheKey, $json);
    }
    return $json['address'];
}

/**
 * Whether a zoom=18 address needs the zoom=10 lookup. For cities zoom=10 adds
 * nothing, so we save the request; towns and villages still need it (ADR 0007).
 */
function needsAdminLookup(array $address): bool {
    return empty($address['city']);
}

/**
 * Replaces gmina/powiat of the zoom=18 address with the zoom=10 ones. Cities
 * come back from zoom=10 without municipality/county — then the zoom=18 values
 * stay (the city key decides there). Everything else (district, postcode,
 * road…) is only reliable at zoom=18 and is left untouched.
 */
function withAdminBoundaries(array $address, ?array $admin): array {
    if (!$admin || !(isset($admin['municipality']) || isset($admin['county'])))
        return $address;
    foreach (['municipality', 'county'] as $key) {
        if (isset($admin[$key])) $address[$key] = $admin[$key];
        else unset($address[$key]);
    }
    return $address;
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

    try {
        $json = nominatimRequest('search', $params);
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

/**
 * Great-circle distance between two points, in meters (haversine, Earth
 * radius 6371 km). Used to detect when a caller-supplied address and
 * caller-supplied coordinates disagree about where something happened.
 */
function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadiusM = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusM * $c;
}

/**
 * Mapbox Static Images URL for the report form's map preview (same style as the web picker,
 * outdoors-v12, with a pin when a point is given; without one: all of Poland). Pure → unit-tested.
 */
function staticMapUrl(?float $lat, ?float $lng, int $width = 600, int $height = 300): string {
    $width = max(100, min(640, $width));
    $height = max(100, min(640, $height));
    if ($lat !== null && $lng !== null) {
        $lat = normalizeGeo($lat);
        $lng = normalizeGeo($lng);
        $overlay = "pin-l+0d7a55($lng,$lat)/";
        $view = "$lng,$lat,15,0";
    } else {
        $overlay = '';
        $view = "19.48,52.07,4.8,0"; // centre of Poland (the web picker's default centre)
    }
    return "https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/static/{$overlay}{$view}/{$width}x{$height}@2x"
        . "?logo=false&access_token=" . MAPBOX_API_TOKEN;
}

/** PNG bytes of staticMapUrl(), or null when Mapbox is unavailable (callers hide the preview). */
function staticMap(?float $lat, ?float $lng, int $width = 600, int $height = 300): ?string {
    $ch = curl_init(staticMapUrl($lat, $lng, $width, $height));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_USERAGENT => "UprzejmieDonosze/1.0"]);
    $png = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($png === false || $code !== 200) {
        \telemetry\log('api_mapbox_static', null, ['status' => 'error', 'http' => $code]);
        return null;
    }
    return $png;
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
        log_info('exifGps: PHP exif extension not available');
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
