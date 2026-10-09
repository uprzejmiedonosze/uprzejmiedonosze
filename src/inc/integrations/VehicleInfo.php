<?PHP namespace vehicle_info;

require_once(__DIR__ . '/curl.php');

use app\Application;
use cache\Type;

/**
 * Vehicle make/model/DMC lookup by plate at parkowanie.info (zbiorkom.live).
 *
 * The only source of vehicle make/model for a report: ALPR guesses are ignored.
 * The result is stored in `$app->carInfo->vehicle` and rendered right after the
 * plate number (Application::getPlateDescription) — never written into the
 * user's comment.
 */

const API_URL = 'https://parkowanie.zbiorkom.live/';
const CACHE_TTL = 7 * 24 * 60 * 60;
const CACHE_TTL_MISS = 24 * 60 * 60;

/** @var callable(string): (array|null) | null */
$GLOBALS['VEHICLE_INFO_FETCHER'] = null;

/**
 * Test override for the HTTP call (raw zbiorkom JSON as an array, or null).
 * null restores the live endpoint. A stubbed fetcher also bypasses the cache.
 */
function setFetcher(?callable $fetcher): void {
    $GLOBALS['VEHICLE_INFO_FETCHER'] = $fetcher;
}

/**
 * Uppercase, no whitespace at all. Returns null for plates too short to look up.
 */
function normalizePlate(?string $plate): ?string {
    $plate = strtoupper(preg_replace('/\s+/u', '', (string) $plate));
    return mb_strlen($plate) < 5 ? null : $plate;
}

/**
 * Normalized vehicle info for a plate, or null when parkowanie.info doesn't
 * know it (or is unreachable):
 *   [plateId, brand, model, grossVehicleWeight (min kg|null), isHeavyVehicle, warning (string|null)]
 */
function lookup(?string $plate): ?array {
    $plate = normalizePlate($plate);
    if ($plate === null) {
        return null;
    }

    $fetcher = $GLOBALS['VEHICLE_INFO_FETCHER'] ?? null;
    if ($fetcher !== null) {
        return normalize($plate, call_user_func($fetcher, $plate));
    }

    $cached = \cache\get(Type::VehicleInfo, $plate);
    if (is_array($cached)) {
        return $cached['info'];
    }

    try {
        $raw = \curl\request(API_URL . rawurlencode($plate), [], 'parkowanie.info', [], 0, 3);
    } catch (\Throwable $e) {
        log_info("vehicle_info: lookup failed for $plate: " . $e->getMessage());
        return null; // don't cache transient failures
    }
    $info = normalize($plate, $raw);
    \cache\set(Type::VehicleInfo, $plate, ['info' => $info], 0, $info === null ? CACHE_TTL_MISS : CACHE_TTL);
    return $info;
}

/**
 * Re-resolves `$app->carInfo->vehicle` when it doesn't match the current plate.
 * Doesn't save the application.
 */
function refresh(Application $app): void {
    $plate = normalizePlate($app->carInfo->plateId ?? null);
    if ($plate === null) {
        unset($app->carInfo->vehicle);
        return;
    }
    if (($app->carInfo->vehicle->plateId ?? null) === $plate) {
        return;
    }
    $info = lookup($plate);
    if ($info === null) {
        unset($app->carInfo->vehicle);
        return;
    }
    $app->carInfo->vehicle = (object) $info;
}

function normalize(string $plate, mixed $data): ?array {
    if (!is_array($data) || isset($data['error'])) {
        return null;
    }
    $brand = trim((string) ($data['brand'] ?? ''));
    $model = trim((string) ($data['model'] ?? ''));
    $gross = minGrossVehicleWeight($data['vehicleInfo']['grossVehicleWeight'] ?? null);
    $isHeavy = ($data['isHeavyVehicle'] ?? false) === true && ($data['vehicleType'] ?? null) === 'TRUCK';

    $warning = null;
    if ($isHeavy) {
        $lines = ['Pojazd jest sklasyfikowany jako ciężarowy.'];
        if ($gross !== null) {
            $lines[] = 'Dopuszczalna masa całkowita wg danych producenta wynosi minimum '
                . formatGrossWeightInTons($gross) . ' t.';
        }
        $lines[] = 'Może to mieć istotne znaczenie przy kwalifikacji wykroczenia.';
        $warning = implode(' ', $lines);
    } elseif ($gross !== null && $gross > 2500) {
        $warning = 'Dopuszczalna masa całkowita wg danych producenta wynosi minimum '
            . formatGrossWeightInTons($gross) . ' t. Może to mieć znaczenie przy parkowaniu na chodniku.';
    }

    if ($brand === '' && $warning === null) {
        return null;
    }

    return [
        'plateId' => $plate,
        'brand' => $brand === '' ? null : formatBrandName($brand),
        'model' => $model === '' ? null : $model,
        'grossVehicleWeight' => $gross,
        'isHeavyVehicle' => $isHeavy,
        'warning' => $warning,
    ];
}

/**
 * Title-cases every word, then applies the shared acronym corrections
 * (BMW, FSO, SsangYong, …).
 */
function formatBrandName(string $brand): string {
    return \fixCapitalizedBrandNames(mb_convert_case(trim($brand), MB_CASE_TITLE, 'UTF-8'));
}

/** The smallest positive value of a weight or weight array, in kg. */
function minGrossVehicleWeight(mixed $value): ?float {
    if (is_array($value)) {
        $nums = array_values(array_filter(array_map('floatval', $value), fn (float $n): bool => $n > 0));
        return $nums ? min($nums) : null;
    }
    $num = is_numeric($value) ? (float) $value : null;
    return $num !== null && $num > 0 ? $num : null;
}

/** kg → "2,60". */
function formatGrossWeightInTons(float $weightKg): string {
    return str_replace('.', ',', sprintf('%.2f', $weightKg / 1000));
}
