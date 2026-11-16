<?PHP namespace assistant\attachments;

require_once(__DIR__ . '/../integrations/Vision.php'); // \vision\normalizePngToJpeg

/**
 * Załączniki do wiadomości w czacie — funkcja dla patronów. Obrazy (JPEG/PNG) zużywają kredyty zdjęć na tych samych
 * zasadach co appka i MCP (\quota\reserve: limit unikalnych zdjęć w oknie, zwrot przy awarii); PDF też zużywa kredyt (1 na plik,
 * niezależnie od liczby stron — model i tak czyta strony jak obrazy).
 * Bajtów nie zapisujemy — w rozmowie zostaje tylko nazwa i typ (meta.attachments); model widzi plik w turze, w której go wysłano.
 */

const MIME_PDF = 'application/pdf';

/** Funkcja tylko dla aktywnych patronów (ten sam warunek co progi \quota\tierFor). */
function isAllowed(\user\User $user): bool {
    return \patronite\is($user->getEmail());
}

function clean(string $name): string {
    $name = preg_replace('/[\x00-\x1f\x7f\/\\\\]+/u', '', basename(str_replace('\\', '/', $name))) ?? '';
    $name = trim(mb_substr($name, 0, 80));
    return $name !== '' ? $name : 'plik';
}

/**
 * Waliduje załączniki z żądania: [{name, data: "data:<mime>;base64,..."}]. PNG zamieniamy na JPEG (jak w analizie zdjęć).
 * @return list<array{name:string,mime:string,bytes:string}>
 * @throws \assistant\ChatException 400 (zły plik/limity), 415 (nieobsługiwany typ)
 */
function decode(mixed $raw): array {
    if ($raw === null || $raw === []) return [];
    if (!is_array($raw) || !array_is_list($raw))
        throw new \assistant\ChatException('Nieprawidłowe załączniki.', 400);
    if (count($raw) > CHAT_MAX_ATTACHMENTS)
        throw new \assistant\ChatException('Zbyt wiele załączników (max ' . CHAT_MAX_ATTACHMENTS . ').', 400);

    $items = [];
    $total = 0;
    foreach ($raw as $a) {
        $name = clean(is_array($a) ? (string)($a['name'] ?? '') : '');
        $data = is_array($a) ? ($a['data'] ?? null) : null;
        if (!is_string($data) || !preg_match('#^data:(image/jpeg|image/jpg|image/png|application/pdf);base64,#i', $data, $m))
            throw new \assistant\ChatException("Załącznik „{$name}” ma nieobsługiwany format (dozwolone: JPEG, PNG, PDF).", 415);
        $bytes = base64_decode(substr($data, strlen($m[0])), true);
        if ($bytes === false || $bytes === '')
            throw new \assistant\ChatException("Załącznik „{$name}” jest uszkodzony.", 400);
        if (strlen($bytes) > CHAT_MAX_ATTACHMENT_BYTES)
            throw new \assistant\ChatException("Załącznik „{$name}” jest za duży (max " . round(CHAT_MAX_ATTACHMENT_BYTES / 1e6, 1) . " MB).", 400);
        $total += strlen($bytes);
        if ($total > CHAT_MAX_ATTACHMENTS_BYTES)
            throw new \assistant\ChatException('Załączniki razem są za duże (max ' . round(CHAT_MAX_ATTACHMENTS_BYTES / 1e6, 1) . ' MB).', 400);

        // Typ ustalamy po zawartości, nie po deklaracji klienta.
        if (str_starts_with($bytes, '%PDF-')) {
            $items[] = ['name' => $name, 'mime' => MIME_PDF, 'bytes' => $bytes];
            continue;
        }
        $mime = (@getimagesizefromstring($bytes) ?: [])['mime'] ?? '';
        if ($mime === 'image/png') {
            $bytes = \vision\normalizePngToJpeg($bytes) ?? throw new \assistant\ChatException("Nie można otworzyć pliku „{$name}”.", 400);
            $mime = 'image/jpeg';
        }
        if ($mime !== 'image/jpeg')
            throw new \assistant\ChatException("Plik „{$name}” nie jest obrazem JPEG/PNG ani PDF-em.", 415);
        $items[] = ['name' => $name, 'mime' => $mime, 'bytes' => $bytes];
    }
    return $items;
}

/**
 * Rezerwuje kredyty zdjęć na każdy załącznik (obraz i PDF) PRZED wywołaniem modelu (jak REST/MCP). To samo zdjęcie w oknie liczy się raz.
 * @param list<array{name:string,mime:string,bytes:string}> $items
 * @return list<string> bajty załączników faktycznie naliczonych — przekaż do release() przy awarii
 * @throws \quota\QuotaExceededException 402 (zwraca już zarezerwowane w tej wiadomości)
 */
function charge(\user\User $user, array $items): array {
    $charged = [];
    try {
        foreach ($items as $item) {
            if (\quota\reserve($user, $item['bytes'])) $charged[] = $item['bytes'];
        }
    } catch (\Throwable $e) {
        release($user, $charged);
        throw $e;
    }
    return $charged;
}

/** @param list<string> $charged */
function release(\user\User $user, array $charged): void {
    foreach ($charged as $bytes) \quota\release($user, $bytes);
}

/** Części treści wiadomości użytkownika dla modelu (tekst + obrazy/PDF). */
function contentParts(string $text, array $items): array {
    $parts = [['type' => 'text', 'text' => $text]];
    foreach ($items as $i) {
        $url = 'data:' . $i['mime'] . ';base64,' . base64_encode($i['bytes']);
        $parts[] = $i['mime'] === MIME_PDF
            ? ['type' => 'file', 'file' => ['filename' => $i['name'], 'file_data' => $url]]
            : ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => OPENAI_CHAT_IMAGE_DETAIL]];
    }
    return $parts;
}

/** Wpis do meta wiadomości (bez bajtów). @return list<array{name:string,type:string}> */
function describe(array $items): array {
    return array_map(fn($i) => ['name' => $i['name'], 'type' => $i['mime'] === MIME_PDF ? 'pdf' : 'image'], $items);
}
