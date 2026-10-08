<?PHP namespace faces;

// Selektywny blur twarzy na publicznych wersjach zdjęcia kontekstowego (miniatura w galerii/
// recydywie, upload na Tumblr). Oryginał contextImage to dowód dla organów — nigdy go nie
// modyfikujemy. Boxy (z integrations/FaceDetect.php -> Google Vision) są znormalizowane do
// ułamków 0..1, więc te same pasują do oryginału i do miniatury.
//
// Celowo osobny plik bez zależności (sam GD): ładowany w include.php, bo blur woła
// Application::generateGalleryImages() także w webappie, a klient API (FaceDetect.php)
// potrzebny jest tylko w face-detect-consumer.

/**
 * Czy wynik detekcji ma boxy do zblurowania (faces.blurred z face-detect-consumer).
 */
function hasBoxes(?object $faces): bool {
    return ($faces->blurred ?? false) === true && !empty((array)($faces->boxes ?? []));
}

/**
 * Rozmywa podane regiony zdjęcia i zwraca nowy JPEG. Margines jak w dawnym dlib-owym
 * face-detectorze (usunięty 2026-10): -20% w lewo/w górę, +40% w prawo/w dół — zapas na
 * niedokładność boxa i ruch głowy między detekcją a kadrem.
 *
 * @param array|object $boxes lista (albo JSONObject z kluczami 0..n z zapisanego zgłoszenia) {x, y, w, h} jako ułamki szerokości/wysokości zdjęcia
 * @throws \RuntimeException gdy bajty nie są obrazkiem
 */
function blur(string $jpegBytes, array|object $boxes): string {
    $img = @imagecreatefromstring($jpegBytes);
    if ($img === false) throw new \RuntimeException('blur: nie można otworzyć obrazka');
    $imgW = imagesx($img);
    $imgH = imagesy($img);

    foreach ((array)$boxes as $box) {
        $box = (array)$box;
        $w = (float)($box['w'] ?? 0) * $imgW;
        $h = (float)($box['h'] ?? 0) * $imgH;
        if ($w <= 0 || $h <= 0) continue;
        $x1 = max(0, (int)floor((float)$box['x'] * $imgW - $w * 0.2));
        $y1 = max(0, (int)floor((float)$box['y'] * $imgH - $h * 0.2));
        $x2 = min($imgW, (int)ceil((float)$box['x'] * $imgW + $w * 1.4));
        $y2 = min($imgH, (int)ceil((float)$box['y'] * $imgH + $h * 1.4));
        blurRegion($img, $x1, $y1, $x2 - $x1, $y2 - $y1);
    }

    ob_start();
    imagejpeg($img, null, 85);
    $out = ob_get_clean();
    imagedestroy($img);
    return $out;
}

// Pomniejszenie do kilku pikseli + powiększenie z powrotem (zostaje tylko średni kolor
// w kilku blokach, nieodwracalne) i kilka przebiegów gaussa, żeby nie było widać kratki.
function blurRegion(\GdImage $img, int $x, int $y, int $w, int $h): void {
    if ($w < 2 || $h < 2) return;
    $blocks = 6;
    $smallW = max(1, min($w, $blocks));
    $smallH = max(1, min($h, (int)round($blocks * $h / $w)));

    $small = imagecreatetruecolor($smallW, $smallH);
    imagecopyresampled($small, $img, 0, 0, $x, $y, $smallW, $smallH, $w, $h);
    $region = imagecreatetruecolor($w, $h);
    imagecopyresampled($region, $small, 0, 0, 0, 0, $w, $h, $smallW, $smallH);
    imagedestroy($small);

    for ($i = 0; $i < 8; $i++) imagefilter($region, IMG_FILTER_GAUSSIAN_BLUR);

    imagecopy($img, $region, $x, $y, 0, 0, $w, $h);
    imagedestroy($region);
}
