<?PHP namespace letter;

require_once(__DIR__ . '/LetterMatcher.php');
require_once(__DIR__ . '/integrations/LetterVision.php');

use Psr\Http\Message\ServerRequestInterface;

// Z pisma do gotowej propozycji edycji zgłoszenia: OCR/ekstrakcja (LetterVision.php) → dopasowanie (LetterMatcher.php) →
// propozycja {status – set, numer sprawy – set, notatka – append}. Klient tylko pokazuje i zatwierdza (applyLetter()).
// Status wyznaczamy tu deterministycznie z `outcome` – model tylko doradza (jak ruleCategory przy zdjęciach).

/** outcome pisma → status UD (null = statusu nie ruszamy, tylko numer sprawy i notatka). */
const OUTCOME_STATUS = [
    'fined' => 'confirmed-fined',
    'instructed' => 'confirmed-instructed',
    'no_offence' => 'confirmed-ignored',
    'perpetrator_unknown' => 'confirmed-ignored',
    'dismissed_other' => 'confirmed-ignored',
    'in_progress' => 'confirmed-sm',
    'closed_unspecified' => null,
    'unknown' => null,
];

const OUTCOME_LABEL = [
    'fined' => 'ukarano mandatem/grzywną',
    'instructed' => 'pouczenie',
    'no_offence' => 'czyn nie zawiera znamion wykroczenia',
    'perpetrator_unknown' => 'niewykrycie sprawcy – brak wniosku o ukaranie',
    'dismissed_other' => 'brak wniosku o ukaranie',
    'in_progress' => 'czynności wyjaśniające w toku',
    'closed_unspecified' => 'czynności wyjaśniające zakończone (bez podania wyniku)',
    'unknown' => 'odpowiedź organu',
];

/** Statusy końcowe: pismo „w toku” ich nie cofa. */
const FINAL_STATUSES = ['confirmed-fined', 'confirmed-instructed', 'confirmed-ignored', 'confirmed-complaint'];

const NOTE_MAX = 2000;
const EXTERNAL_ID_MAX = 100;

/** Kształt kandydata dla LetterMatcher z odszyfrowanego zgłoszenia. */
function candidateFromApplication(\app\Application $app): array {
    $vehicle = $app->carInfo->vehicle ?? null;
    return [
        'id' => $app->id,
        'number' => $app->number ?? null,
        'plate' => $app->carInfo->plateId ?? null,
        'date' => $app->date ?? null,
        'address' => $app->address->address ?? null,
        'externalId' => $app->externalId ?? '',
        'privateComment' => $app->privateComment ?? '',
        'vehicle' => $vehicle ? trim(($vehicle->brand ?? '') . ' ' . ($vehicle->model ?? '')) : null,
        'status' => $app->status ?? null,
    ];
}

/** @return array{status: ?string, blocked: ?array} */
function proposeStatus(string $outcome, ?string $current, ?array $statuses = null): array {
    global $STATUSES;
    $statuses ??= (array)$STATUSES;
    $to = OUTCOME_STATUS[$outcome] ?? null;
    if ($to === null || $current === null || $to === $current) return ['status' => null, 'blocked' => null];
    if ($outcome === 'in_progress' && in_array($current, FINAL_STATUSES, true)) return ['status' => null, 'blocked' => null];

    $allowed = ((array)($statuses[$current] ?? []))['allowed'] ?? [];
    if (!in_array($to, $allowed, true)) {
        $name = fn (string $s) => ((array)($statuses[$s] ?? []))['name'] ?? $s;
        return ['status' => null, 'blocked' => [
            'from' => $current,
            'to' => $to,
            'reason' => "Zmiana statusu z „{$name($current)}” na „{$name($to)}” nie jest dozwolona.",
        ]];
    }
    return ['status' => $to, 'blocked' => null];
}

/** Numer sprawy do zapisania w externalId: RSOW > eRSOW > znak sprawy > L.dz. */
function proposeExternalId(array $ids): ?string {
    if (!empty($ids['rsow'])) return 'RSOW ' . $ids['rsow'];
    if (!empty($ids['ersow'])) return 'eRSOW ' . $ids['ersow'];
    if (!empty($ids['caseMark'])) return (string)$ids['caseMark'];
    if (!empty($ids['ldz'])) return 'L.dz. ' . $ids['ldz'];
    return null;
}

function buildNote(array $letter, array $case, ?string $externalId): string {
    $date = !empty($letter['date']) ? date('d.m.Y', strtotime($letter['date'])) : null;
    $head = $date !== null ? "[Pismo z $date] " : '[Pismo] ';
    $parts = [($letter['authority'] ?? null) ? $letter['authority'] . ': ' : ''];
    $text = OUTCOME_LABEL[$case['outcome']] ?? OUTCOME_LABEL['unknown'];
    $reason = $case['reason'] ?? null;
    if ($reason !== null && !str_contains(fold($text), fold($reason)) && !str_contains(fold($reason), fold($text)))
        $text .= ' (' . mb_substr($reason, 0, 200) . ')';
    $parts[] = $text;
    $tail = [];
    if ($externalId !== null) $tail[] = "sprawa $externalId";
    if (!empty($case['ids']['mandateNo'])) $tail[] = 'mandat ' . $case['ids']['mandateNo'];
    return $head . implode('', $parts) . ($tail ? '; ' . implode('; ', $tail) : '');
}

/**
 * Propozycja edycji jednego zgłoszenia dla sprawy z pisma.
 * @return array{set: array{status: ?string, externalId: ?string}, append: array{privateComment: ?string},
 *               statusBlocked: ?array, conflicts: list<array>, noop: bool}
 */
function proposeEdit(array $case, array $letter, array $candidate, ?array $statuses = null): array {
    ['status' => $status, 'blocked' => $blocked] = proposeStatus($case['outcome'], $candidate['status'] ?? null, $statuses);

    $conflicts = [];
    $externalId = proposeExternalId($case['ids'] ?? []);
    $current = trim((string)($candidate['externalId'] ?? ''));
    if ($externalId !== null && $current !== '') {
        if (str_contains(idFold($current), idFold($externalId))) {
            $externalId = null; // już zapisany
        } else {
            $conflicts[] = ['field' => 'externalId', 'current' => $current, 'proposed' => $externalId];
            $externalId = null;
        }
    }

    $note = buildNote($letter, $case, proposeExternalId($case['ids'] ?? []));
    $existing = (string)($candidate['privateComment'] ?? '');
    $note = mb_substr($note, 0, NOTE_MAX);
    $appendNote = str_contains($existing, $note) ? null : $note;

    return [
        'set' => ['status' => $status, 'externalId' => $externalId],
        'append' => ['privateComment' => $appendNote],
        'statusBlocked' => $blocked,
        'conflicts' => $conflicts,
        'noop' => $status === null && $externalId === null && $appendNote === null,
    ];
}

/** Fragment sprawy z pisma, który ma sens pokazać klientowi. */
function publicCase(array $case): array {
    return array_intersect_key($case, array_flip(['ids', 'plate', 'vehicleMake', 'date', 'time', 'address', 'outcome', 'reason', 'articles', 'confidence']));
}

/**
 * Strony pisma z żądania multipart: `images[]` (kilka plików) albo `image` (jeden). JPEG/PNG, PNG → JPEG.
 * @return list<string> bajty JPEG
 * @throws \Exception 400 brak/zbyt duży plik, 415 zły format
 */
function pagesFromRequest(ServerRequestInterface $request): array {
    $files = $request->getUploadedFiles();
    $uploads = [];
    foreach (['images', 'image'] as $key) {
        if (!isset($files[$key])) continue;
        foreach (is_array($files[$key]) ? $files[$key] : [$files[$key]] as $u) $uploads[] = $u;
    }
    if (!$uploads) throw new \Exception('Brak pliku obrazka', 400);
    if (count($uploads) > LETTER_MAX_PAGES) throw new \Exception('Zbyt wiele stron (max ' . LETTER_MAX_PAGES . ')', 400);

    $pages = [];
    foreach ($uploads as $u) {
        if ($u->getError() !== UPLOAD_ERR_OK) throw new \Exception("Błąd przesyłania pliku (kod {$u->getError()})", 400);
        if ($u->getSize() > \MAX_IMAGE_UPLOAD_BYTES) throw new \Exception('Zbyt duże zdjęcie (max ' . (\MAX_IMAGE_UPLOAD_BYTES / 1_048_576) . 'MB)', 400);
        $bytes = $u->getStream()->getContents();
        if ($bytes === '') throw new \Exception('Brak pliku obrazka', 400);
        $mime = @getimagesizefromstring($bytes)['mime'] ?? null;
        if ($mime === 'image/png') {
            $bytes = \vision\normalizePngToJpeg($bytes) ?? throw new \Exception('Nie można otworzyć pliku PNG', 400);
        } elseif ($mime !== 'image/jpeg') {
            throw new \Exception('Nieobsługiwany format obrazka: ' . ($mime ?? 'nieznany'), 415);
        }
        $pages[] = $bytes;
    }
    return $pages;
}

/**
 * Cały przepływ dla użytkownika: kredyty (1 na unikalną stronę) → OCR/ekstrakcja → dopasowanie → propozycje.
 * Wynik ekstrakcji (bez transkryptu – dane osobowe) jest cache'owany po sha1 stron, więc ponowne wgranie tego samego skanu
 * nic nie kosztuje. Skan nie jest nigdzie zapisywany.
 * @param list<string> $jpegPages
 * @throws \quota\QuotaExceededException 402
 * @throws \vision\VisionException błąd modelu (502 w warstwie REST)
 */
function analyzeForUser(\user\User $user, array $jpegPages): array {
    $key = sha1($user->getEmail() . '|' . implode('|', array_map('sha1', $jpegPages)));
    $extracted = \cache\get(\cache\Type::Letter, $key);
    $usage = null;

    if (!is_array($extracted)) {
        $charged = [];
        try {
            foreach ($jpegPages as $bytes) {
                if (\quota\reserve($user, $bytes)) $charged[] = $bytes;
            }
            $extracted = analyzeLetter($jpegPages);
        } catch (\Throwable $e) {
            foreach ($charged as $bytes) \quota\release($user, $bytes);
            \telemetry\log('api_letter', null, ['status' => 'error']);
            throw $e;
        }
        \telemetry\log('api_letter', null, ['status' => 'success']);
        log_info(sprintf(
            'letter cost: pages=%d calls=%d prompt_tokens=%d completion_tokens=%d cost_usd=%.5f model=%s user=%s',
            count($jpegPages), $extracted['usage']['calls'], $extracted['usage']['prompt_tokens'],
            $extracted['usage']['completion_tokens'], $extracted['usage']['cost_usd'], OPENAI_LETTER_MODEL, $user->getEmail()
        ), true);
        unset($extracted['transcript']);
        \cache\set(\cache\Type::Letter, $key, $extracted);
        $usage = $extracted['usage'];
    }

    return buildResponse($user, $extracted, $usage);
}

/** Dopasowanie i propozycje dla gotowej ekstrakcji (bez sieci i kredytów). */
function buildResponse(\user\User $user, array $extracted, ?array $usage = null): array {
    $candidates = array_map(fn ($a) => candidateFromApplication($a), \user\apps($user, 'all'));
    $byId = [];
    foreach ($candidates as $c) $byId[$c['id']] = $c;

    $cases = [];
    foreach ($extracted['cases'] as $i => $case) {
        $match = matchCase($case, $candidates, $user->getNumber() !== null ? (int)$user->getNumber() : null);
        foreach ($match['candidates'] as &$cand) {
            $cand['proposal'] = proposeEdit($case, $extracted['letter'], $byId[$cand['appId']]);
        }
        unset($cand);
        $cases[] = [
            'index' => $i,
            'case' => publicCase($case),
            'match' => ['level' => $match['level'], 'appId' => $match['level'] === 'match' ? $match['candidates'][0]['appId'] : null],
            'candidates' => $match['candidates'],
        ];
    }
    return [
        'letter' => $extracted['letter'],
        'cases' => $cases,
        'warnings' => $extracted['warnings'] ?? [],
        'usage' => $usage, // null przy trafieniu w cache (nic nie kosztowało)
    ];
}

/**
 * Zatwierdzenie propozycji: status, numer sprawy i dopisanie notatki w jednej blokadzie (bez wyścigu odczyt-zmiana-zapis
 * po stronie klienta). Notatkę dopisujemy po stronie serwera do privateComment.
 * @param array<string,mixed> $body status?, externalId?, appendNote?, letterSummary?
 * @throws \InvalidArgumentException zły kształt/wartość (400)
 * @throws \Exception 403 nie właściciel, 409 niedozwolona zmiana statusu
 */
function applyLetter(string $appId, array $body, \user\User $user): \app\Application {
    global $STATUSES;
    $status = $body['status'] ?? null;
    $externalId = $body['externalId'] ?? null;
    $appendNote = $body['appendNote'] ?? null;
    $summary = $body['letterSummary'] ?? null;
    foreach (['status' => $status, 'externalId' => $externalId, 'appendNote' => $appendNote, 'letterSummary' => $summary] as $field => $v) {
        if ($v !== null && !is_string($v)) throw new \InvalidArgumentException("Pole $field musi być tekstem");
    }
    $status = $status === null || $status === '' ? null : $status;
    if ($status !== null && !array_key_exists($status, (array)$STATUSES)) throw new \InvalidArgumentException("Nieznany status '$status'");
    $externalId = $externalId === null ? null : trim($externalId);
    if ($externalId !== null && mb_strlen($externalId) > EXTERNAL_ID_MAX) throw new \InvalidArgumentException('Numer sprawy jest zbyt długi');
    $appendNote = $appendNote === null ? null : trim($appendNote);
    if ($appendNote !== null && mb_strlen($appendNote) > NOTE_MAX) throw new \InvalidArgumentException('Notatka jest zbyt długa');

    $application = \semaphore\withLock($appId, 'applyLetter', function () use ($appId, $user, $status, $externalId, $appendNote, $summary) {
        $application = \app\get($appId);
        if ($application->email !== $user->getEmail())
            throw new \Exception("Nie posiadasz zgłoszenia o ID $appId", 403);

        $statusChanged = false;
        if ($status !== null && $status !== $application->status) {
            try {
                $application->setStatus($status);
            } catch (\Exception $e) {
                throw new \Exception($e->getMessage(), 409, $e);
            }
            $statusChanged = true;
        }
        if ($externalId !== null && $externalId !== '') $application->externalId = $externalId;
        if ($appendNote !== null && $appendNote !== '') {
            $existing = (string)($application->privateComment ?? '');
            if (!str_contains($existing, $appendNote))
                $application->privateComment = $existing === '' ? $appendNote : $existing . "\n" . $appendNote;
        }
        $application->addComment('letter', ($summary !== null && $summary !== '') ? $summary : ($appendNote ?? 'Pismo'), $statusChanged ? $status : null);
        $saved = \app\save($application);
        return [$saved, $statusChanged];
    });
    [$saved, $statusChanged] = $application;
    if ($statusChanged) \user\stats(false, $user); // odśwież cache licznika
    \telemetry\log('report_edited', $appId, ['type' => 'letter']);
    return $saved;
}
