<?PHP namespace wip;

use user\User;

/**
 * Etap „wip” zdjęć: surowe zdjęcia z aplikacji mobilnej trafiają do `{cdn}/{userNumber}/wip/{photoId}.jpg` ZANIM
 * powstanie z nich zdjęcie zgłoszenia. Tu są analizowane (vision + ALPR – po `photoId`, bez ponownego przesyłania), a dopiero
 * przy przydziale do slotu (API.php::assignPhoto → finalizeImage) skalowane, przycinane i zapisywane jako
 * `{appId},{type}.jpg`. Pliki `wip` NIE są synchronizowane do S3 (patrz tools/common.php::purgeLocalFiles) i znikają po
 * zakończeniu zgłoszenia albo po WIP_TTL_HOURS (tools/cleanup.php).
 *
 * Obok zdjęcia leży sidecar `{photoId}.json`: wymiary, metadane od klienta (data/GPS z EXIF) i wynik ALPR – dzięki temu
 * każde zdjęcie jest czytane przez ALPR dokładnie raz, niezależnie od liczby przydziałów (np. po zamianie ról).
 */

const WIP_TTL_HOURS = 24;
const ID_PATTERN = '/^[a-f0-9]{32}$/';

/** Katalog użytkownika względem ROOT (ten sam układ co zdjęcia zgłoszeń). */
function relDir(User $user): string {
    return \storage\cdnPrefix() . '/' . $user->getNumber() . '/wip';
}

function validId(string $photoId): bool {
    return preg_match(ID_PATTERN, $photoId) === 1;
}

function photoPath(User $user, string $photoId): string {
    return ROOT . relDir($user) . "/$photoId.jpg";
}

function sidecarPath(User $user, string $photoId): string {
    return ROOT . relDir($user) . "/$photoId.json";
}

function toJpeg(string $bytes): string {
    $info = @getimagesizefromstring($bytes);
    if (!$info) throw new \Exception('Przesłany plik nie jest obrazkiem', 400);
    $mime = $info['mime'] ?? '';
    if ($mime === 'image/jpeg') return $bytes;
    if ($mime !== 'image/png') throw new \Exception("Nieobsługiwany format obrazka: $mime", 415);

    $src = @imagecreatefromstring($bytes);
    if ($src === false) throw new \Exception('Nie można otworzyć pliku PNG', 400);
    $img = imagecreatetruecolor(imagesx($src), imagesy($src));
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imagecopy($img, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
    imagedestroy($src);
    ob_start();
    imagejpeg($img, null, 90);
    $jpeg = ob_get_clean();
    imagedestroy($img);
    return $jpeg;
}

/**
 * Zapisuje zdjęcie w `wip` (bez zmniejszania i re-enkodowania JPEG).
 * @param array{dateTime?:?string,lat?:mixed,lng?:mixed} $meta data i GPS wyczytane przez klienta z EXIF
 * @return array{photoId:string,width:int,height:int}
 */
function stage(User $user, string $bytes, array $meta = []): array {
    $jpeg = toJpeg($bytes);
    [$width, $height] = getimagesizefromstring($jpeg);

    $dir = ROOT . relDir($user);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new \Exception('Nie udało się zapisać zdjęcia', 500);
    }

    $photoId = bin2hex(random_bytes(16));
    if (file_put_contents(photoPath($user, $photoId), $jpeg) === false) {
        throw new \Exception('Nie udało się zapisać zdjęcia', 500);
    }

    $lat = $meta['lat'] ?? null;
    $lng = $meta['lng'] ?? null;
    writeSidecar($user, $photoId, [
        'sha1' => sha1($jpeg),
        'width' => $width,
        'height' => $height,
        'dateTime' => isset($meta['dateTime']) && is_string($meta['dateTime']) ? substr($meta['dateTime'], 0, 32) : null,
        'lat' => is_numeric($lat) ? (float)$lat : null,
        'lng' => is_numeric($lng) ? (float)$lng : null,
        'alpr' => null,
        'createdAt' => time(),
    ]);
    return ['photoId' => $photoId, 'width' => $width, 'height' => $height];
}

function writeSidecar(User $user, string $photoId, array $data): void {
    file_put_contents(sidecarPath($user, $photoId), json_encode($data));
}

/** @return array<string,mixed> */
function readSidecar(User $user, string $photoId): array {
    $path = sidecarPath($user, $photoId);
    $raw = is_file($path) ? file_get_contents($path) : false;
    $data = $raw === false ? null : json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Zdjęcie użytkownika z `wip`: bajty + sidecar. null = brak (zły identyfikator, cudze albo wygasłe zdjęcie).
 * @return array{bytes:string,meta:array<string,mixed>}|null
 */
function load(User $user, string $photoId): ?array {
    if (!validId($photoId)) return null;
    $path = photoPath($user, $photoId);
    $bytes = is_file($path) ? file_get_contents($path) : false;
    if ($bytes === false || $bytes === '') return null;
    return ['bytes' => $bytes, 'meta' => readSidecar($user, $photoId)];
}

/**
 * Wynik PlateRecognizer dla zdjęcia z `wip` – liczony raz i zapisany w sidecarze (kolejne wywołania nie kosztują).
 * Współrzędne w wyniku są w pikselach pliku `wip`. Błąd dostawcy → wyjątek (nic nie zapisujemy, kolejna próba policzy od nowa).
 * @return array<string,mixed>|null null = brak zdjęcia
 */
function alpr(User $user, string $photoId): ?array {
    $photo = load($user, $photoId);
    if (!$photo) return null;
    if (is_array($photo['meta']['alpr'] ?? null)) return $photo['meta']['alpr'];

    $resp = \vision\PlateRecognizerClient::call($photo['bytes']);
    $meta = $photo['meta'];
    $meta['alpr'] = $resp;
    writeSidecar($user, $photoId, $meta);
    return $resp;
}

/**
 * Detekcje ALPR zdjęcia w formie dla klienta mobilnego (wybór auta/kontekstu po stronie aplikacji): każdy odczyt z tekstem
 * tablicy, pewnością, boxami (0..1000) i polem pojazdu (ułamek kadru) + `winner` = odczyt wskazany przez bestAlprResult()
 * (score z pasmem remisu → większy pojazd). ALPR liczony raz na zdjęcie (sidecar).
 * @return array{photoId:string,width:int,height:int,detections:list<array<string,mixed>>}|null null = brak zdjęcia
 */
function detections(User $user, string $photoId): ?array {
    $resp = alpr($user, $photoId);
    if ($resp === null) return null;
    $meta = readSidecar($user, $photoId);
    $size = ['w' => (int)($meta['width'] ?? 0), 'h' => (int)($meta['height'] ?? 0)];
    $best = \alpr\bestAlprResult($resp);
    $out = [];
    foreach ($resp['results'] ?? [] as $r) {
        $d = \vision\alprDetections(['results' => [$r]], $size['w'] > 0 && $size['h'] > 0 ? $size : null)[0] ?? null;
        if ($d === null) continue; // odczyt bez parsowalnej tablicy
        $d['winner'] = $best !== null && $r === $best;
        $out[] = $d;
    }
    return ['photoId' => $photoId, 'width' => $size['w'], 'height' => $size['h'], 'detections' => $out];
}

/**
 * Wycinek auta dla zdjęcia z `wip`: bajty JPEG fragmentu z boxem zwycięskiego pojazdu +20% i wynik ALPR przesunięty do
 * współrzędnych wycinka (tylko zwycięzca) – dzięki temu przydział nie woła ALPR drugi raz.
 * @return array{bytes:string,alpr:array<string,mixed>,size:array{0:int,1:int}}|null null = brak pojazdu / zdjęcia
 */
function vehicleCrop(User $user, string $photoId, float $margin = 0.2): ?array {
    $photo = load($user, $photoId);
    $resp = $photo ? alpr($user, $photoId) : null;
    $best = $resp ? \alpr\bestAlprResult($resp) : null;
    $box = $best['vehicle']['box'] ?? null;
    if (!$box) return null;

    $w = (int)($photo['meta']['width'] ?? 0);
    $h = (int)($photo['meta']['height'] ?? 0);
    if ($w <= 0 || $h <= 0) [$w, $h] = getimagesizefromstring($photo['bytes']);
    [$x, $y, $cw, $ch] = \alpr\vehicleCropBox($box, $w, $h, $margin);

    $src = @imagecreatefromstring($photo['bytes']);
    if ($src === false) return null;
    $cropped = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $cw, 'height' => $ch]);
    imagedestroy($src);
    if ($cropped === false) return null;
    ob_start();
    imagejpeg($cropped, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($cropped);

    return ['bytes' => $bytes, 'alpr' => \alpr\translatePlateRecognizerResult(['results' => [$best]], -$x, -$y), 'size' => [$cw, $ch]];
}

function delete(User $user, string $photoId): bool {
    if (!validId($photoId)) return false;
    $existed = is_file(photoPath($user, $photoId));
    @unlink(photoPath($user, $photoId)); // nosemgrep: php.lang.security.unlink-use.unlink-use
    @unlink(sidecarPath($user, $photoId)); // nosemgrep: php.lang.security.unlink-use.unlink-use
    return $existed;
}

/** Kasuje pliki `wip` starsze niż $hours (cron: tools/cleanup.php). Zwraca liczbę skasowanych plików. */
function purgeOlderThan(int $hours = WIP_TTL_HOURS): int {
    $removed = 0;
    $limit = time() - $hours * 3600;
    foreach (glob(ROOT . \storage\cdnPrefix() . '/*/wip/*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $limit && @unlink($file)) { // nosemgrep: php.lang.security.unlink-use.unlink-use
            $removed++;
        }
    }
    return $removed;
}
