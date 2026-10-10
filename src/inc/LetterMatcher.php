<?PHP namespace letter;

// Dopasowanie sprawy z pisma (letter\analyzeLetter()) do zgłoszeń użytkownika. Czyste funkcje, bez bazy i sieci –
// kandydatów buduje LetterProposal.php (candidateFromApplication).
//
// Kolejność sygnałów (malejąca waga): numer UD → numer sprawy (RSOW/eRSOW/L.dz./znak) → numer rejestracyjny → data → adres.
// Policjanci mylą się w numerach rej., datach i adresach, więc każdy z nich porównujemy „rozmyto” (patrz *Similarity()).
//
// Kandydat: ['id','number','plate','date'(Y-m-dTH:i:s),'address','externalId','vehicle','status'].

const W_PLATE = 42;
const W_DATE = 28;
const W_ADDRESS = 20;
const W_MAKE = 5;
const W_TIME = 5;

const SCORE_UD = 100;
const SCORE_EXTERNAL_ID = 95;
const LEVEL_MATCH = 80;
const LEVEL_CANDIDATES = 50;
const MIN_LISTED = 30;
const MAX_LISTED = 3;
const TIE_MARGIN = 10;

/** Wartość id (numeru sprawy) do porównań: tylko litery/cyfry, wielkie. */
function idFold(string $v): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/u', '', $v));
}

/** Małe litery bez polskich znaków (jak \user\searchFold, ale bez zależności od bazy). */
function fold(string $text): string {
    return strtr(mb_strtolower($text), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
}

/** Odległość Damerau-Levenshteina (wstaw/usuń/zamień/transpozycja sąsiednich znaków). */
function damerauLevenshtein(string $a, string $b): int {
    $la = strlen($a);
    $lb = strlen($b);
    if ($la === 0) return $lb;
    if ($lb === 0) return $la;
    $d = [];
    for ($i = 0; $i <= $la; $i++) $d[$i][0] = $i;
    for ($j = 0; $j <= $lb; $j++) $d[0][$j] = $j;
    for ($i = 1; $i <= $la; $i++) {
        for ($j = 1; $j <= $lb; $j++) {
            $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
            $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
            if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1])
                $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
        }
    }
    return $d[$la][$lb];
}

/** Znaki mylone na tablicach (OCR i ludzie): O/0, I/1, B/8, S/5, Z/2 sprowadzamy do cyfr. */
function confusableFold(string $plate): string {
    return strtr($plate, ['O' => '0', 'Q' => '0', 'I' => '1', 'B' => '8', 'S' => '5', 'Z' => '2']);
}

function plateStrip(?string $plate): ?string {
    if ($plate === null) return null;
    $s = strtoupper(preg_replace('/[^A-Za-z0-9?*]/', '', $plate));
    return $s === '' ? null : $s;
}

/** @return array{0: float, 1: string} [podobieństwo 0..1, rodzaj: exact|confusable|masked|fuzzy1|fuzzy2|none] */
function plateSimilarity(?string $a, ?string $b): array {
    $a = plateStrip($a);
    $b = plateStrip($b);
    if ($a === null || $b === null) return [0.0, 'none'];
    if (str_contains($a, '?') || str_contains($a, '*')) { // zamazane znaki w piśmie
        $re = '/^' . str_replace(['\?', '\*'], ['.', '.*'], preg_quote($a, '/')) . '$/';
        return preg_match($re, $b) ? [0.85, 'masked'] : [0.0, 'none'];
    }
    if ($a === $b) return [1.0, 'exact'];
    $ca = confusableFold($a);
    $cb = confusableFold($b);
    if ($ca === $cb) return [0.9, 'confusable'];
    $dist = damerauLevenshtein($ca, $cb);
    if ($dist <= 1) return [0.75, 'fuzzy1'];
    if ($dist === 2 && min(strlen($a), strlen($b)) >= 7) return [0.45, 'fuzzy2'];
    return [0.0, 'none'];
}

/**
 * Data wykroczenia z pisma ('Y-m-d' albo '--m-d' gdy bez roku) vs data zgłoszenia.
 * @return array{0: float, 1: string} kind: exact|±1d|±3d|swapped|sameMonth|none
 */
function dateSimilarity(?string $caseDate, ?string $candidateDate): array {
    if ($caseDate === null || $candidateDate === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $candidateDate, $c)) return [0.0, 'none'];
    $cy = (int)$c[1];
    $cm = (int)$c[2];
    $cd = (int)$c[3];

    if (preg_match('/^--(\d{2})-(\d{2})$/', $caseDate, $m)) {
        $y = $cy;
        $month = (int)$m[1];
        $day = (int)$m[2];
        if (!checkdate($month, $day, $y)) return [0.0, 'none'];
    } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $caseDate, $m)) {
        $y = (int)$m[1];
        $month = (int)$m[2];
        $day = (int)$m[3];
        if (!checkdate($month, $day, $y)) return [0.0, 'none'];
    } else {
        return [0.0, 'none'];
    }

    $a = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $month, $day));
    $b = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $cy, $cm, $cd));
    $diff = abs((int)$a->diff($b)->format('%r%a'));
    if ($diff === 0) return [1.0, 'exact'];
    if ($diff === 1) return [0.8, '±1d'];
    if ($diff <= 3) return [0.5, '±3d'];
    if ($day <= 12 && $month <= 12 && $day !== $month && $cy === $y && $cm === $day && $cd === $month) return [0.55, 'swapped'];
    if ($cy === $y && $cm === $month) return [0.15, 'sameMonth'];
    return [0.0, 'none'];
}

/** @return array{0: float, 1: string} kind: exact|near|differs|none */
function timeSimilarity(?string $caseTime, ?string $candidateDate): array {
    if ($caseTime === null || $candidateDate === null || !preg_match('/[T ](\d{2}):(\d{2})/', $candidateDate, $c)) return [0.0, 'none'];
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $caseTime, $t)) return [0.0, 'none'];
    $delta = abs(((int)$t[1] * 60 + (int)$t[2]) - ((int)$c[1] * 60 + (int)$c[2]));
    if ($delta <= 5) return [1.0, 'exact'];
    if ($delta <= 30) return [0.6, 'near'];
    return [0.0, 'differs'];
}

/** @return list<string> */
function streetTokens(string $street): array {
    $s = fold($street);
    $s = preg_replace('/\b(ulica|ul|aleja|aleje|al|plac|pl|osiedle|os)\b\.?/u', ' ', $s);
    $tokens = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter($tokens, fn ($t) => strlen($t) >= 3));
}

/** Czy odmieniona/przekręcona nazwa ulicy pasuje: wspólny rdzeń (końcówki fleksyjne) albo literówka. */
function tokenMatches(string $a, string $b): bool {
    if ($a === $b) return true;
    $min = min(strlen($a), strlen($b));
    $prefix = 0;
    while ($prefix < $min && $a[$prefix] === $b[$prefix]) $prefix++;
    if ($min >= 5 && $prefix >= max(4, $min - 3)) return true;
    return $min >= 5 && damerauLevenshtein($a, $b) <= 1;
}

/**
 * Adres z pisma {street, number, city} vs adres zgłoszenia (jeden tekst).
 * @return array{0: float, 1: string} kind: street|streetAndNumber|city|none
 */
function addressSimilarity(array $caseAddress, ?string $candidateAddress): array {
    if ($candidateAddress === null || trim($candidateAddress) === '') return [0.0, 'none'];
    $folded = fold($candidateAddress);
    $candTokens = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY);

    $street = $caseAddress['street'] ?? null;
    if ($street !== null && ($tokens = streetTokens($street))) {
        $hits = 0;
        foreach ($tokens as $t) {
            foreach ($candTokens as $ct) {
                if (strlen($ct) >= 3 && tokenMatches($t, $ct)) { $hits++; break; }
            }
        }
        if ($hits === 0) return [0.0, 'none']; // inna ulica – samo miasto tego nie ratuje
        $streetScore = $hits / count($tokens);
        $number = $caseAddress['number'] ?? null;
        if ($number !== null && $streetScore > 0) {
            $n = fold($number);
            $numberHit = in_array($n, $candTokens, true);
            return [$streetScore * 0.8 + ($numberHit ? 0.2 : 0.0), $numberHit ? 'streetAndNumber' : 'street'];
        }
        return [$streetScore * 0.85, 'street'];
    }

    $city = $caseAddress['city'] ?? null;
    if ($city !== null) {
        $c = fold($city);
        foreach ($candTokens as $ct) {
            if (strlen($ct) >= 4 && tokenMatches($c, $ct)) return [0.4, 'city'];
        }
    }
    return [0.0, 'none'];
}

function makeSimilarity(?string $caseMake, ?string $candidateVehicle): float {
    if ($caseMake === null || $candidateVehicle === null) return 0.0;
    $vehicle = fold($candidateVehicle);
    foreach (preg_split('/[^a-z0-9]+/', fold($caseMake), -1, PREG_SPLIT_NO_EMPTY) as $t) {
        if (strlen($t) >= 3 && str_contains($vehicle, $t)) return 1.0;
    }
    return 0.0;
}

/** Wartości numeru sprawy z pisma do porównania z externalId zgłoszenia (RSOW/eRSOW/L.dz./znak). */
function caseNumberValues(array $ids): array {
    $values = [];
    foreach (['rsow', 'ersow', 'ldz', 'caseMark'] as $k) {
        if (!empty($ids[$k]) && strlen(idFold((string)$ids[$k])) >= 4) $values[] = idFold((string)$ids[$k]);
    }
    return $values;
}

/** Wynik porównania jednej sprawy z jednym kandydatem: ['score'=>0..100,'signals'=>[...]]. */
function scoreCandidate(array $case, array $cand, ?int $userNo): array {
    $ids = $case['ids'] ?? [];
    $signals = [];
    [$plateSim, $plateKind] = plateSimilarity($case['plate'] ?? null, $cand['plate'] ?? null);
    [$dateSim, $dateKind] = dateSimilarity($case['date'] ?? null, $cand['date'] ?? null);
    [$timeSim, $timeKind] = timeSimilarity($case['time'] ?? null, $cand['date'] ?? null);
    [$addrSim, $addrKind] = addressSimilarity($case['address'] ?? [], $cand['address'] ?? null);
    $makeSim = makeSimilarity($case['vehicleMake'] ?? null, $cand['vehicle'] ?? null);
    $plateKnown = ($case['plate'] ?? null) !== null && ($cand['plate'] ?? null) !== null;
    $dateKnown = ($case['date'] ?? null) !== null && ($cand['date'] ?? null) !== null;

    if ($plateKind !== 'none') $signals['plate'] = $plateKind;
    if ($dateKind !== 'none') $signals['date'] = $dateKind;
    if ($timeKind === 'exact' || $timeKind === 'near') $signals['time'] = $timeKind;
    if ($addrKind !== 'none') $signals['address'] = $addrKind;
    if ($makeSim > 0) $signals['make'] = 'match';

    // 1) numer UD – tylko własny prefiks (pisma zbiorcze mogą wymieniać numery innych użytkowników)
    $ud = $ids['udNumber'] ?? null;
    if ($ud !== null && preg_match('/^UD\/(\d+)\/(\d+)$/i', $ud, $m)
        && ($userNo === null || (int)$m[1] === $userNo)
        && strcasecmp($ud, (string)($cand['number'] ?? '')) === 0) {
        return ['score' => SCORE_UD, 'signals' => ['ud' => 'exact'] + $signals];
    }

    $score = W_PLATE * $plateSim + W_DATE * $dateSim + W_ADDRESS * $addrSim + W_MAKE * $makeSim + W_TIME * $timeSim;

    // 2) numer sprawy zapisany już w zgłoszeniu (pisma następcze w tej samej sprawie)
    $ext = idFold((string)($cand['externalId'] ?? ''));
    if ($ext !== '') {
        foreach (caseNumberValues($ids) as $v) {
            if (str_contains($ext, $v)) {
                $signals['externalId'] = 'exact';
                $score = max($score, SCORE_EXTERNAL_ID);
                break;
            }
        }
    }

    // sprzeczności: inny pojazd lub inny dzień nie może wyjść na „dopasowano”
    if ($plateKnown && $plateSim < 0.4) $score = min($score, 35);
    elseif ($dateKnown && $dateSim === 0.0 && !isset($signals['externalId'])) $score = min($score, 45);

    return ['score' => (int)round(min(100, $score)), 'signals' => $signals];
}

/**
 * Dopasowuje jedną sprawę z pisma do kandydatów.
 * @param list<array> $candidates
 * @return array{level: string, candidates: list<array>} level: match|candidates|none; kandydaci malejąco wg score, max MAX_LISTED
 */
function matchCase(array $case, array $candidates, ?int $userNo = null): array {
    $scored = [];
    foreach ($candidates as $cand) {
        $r = scoreCandidate($case, $cand, $userNo);
        $scored[] = ['cand' => $cand, 'score' => $r['score'], 'signals' => $r['signals']];
    }

    // jedyny pojazd o tej tablicy, a pismo nie podaje daty (nic nie przeczy) – pokaż go jako kandydata
    if (($case['date'] ?? null) === null) {
        $plateHits = array_keys(array_filter($scored, fn ($s) => in_array($s['signals']['plate'] ?? null, ['exact', 'confusable', 'masked'], true)));
        if (count($plateHits) === 1 && $scored[$plateHits[0]]['score'] < 70) {
            $scored[$plateHits[0]]['score'] = 70;
            $scored[$plateHits[0]]['signals']['uniquePlate'] = true;
        }
    }

    usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
    $listed = array_values(array_filter($scored, fn ($s) => $s['score'] >= MIN_LISTED));
    $listed = array_slice($listed, 0, MAX_LISTED);

    $level = 'none';
    if ($listed) {
        $best = $listed[0]['score'];
        $tied = isset($listed[1]) && $best - $listed[1]['score'] < TIE_MARGIN;
        if ($best >= LEVEL_MATCH && !$tied) $level = 'match';
        elseif ($best >= LEVEL_CANDIDATES) $level = 'candidates';
    }

    return [
        'level' => $level,
        'candidates' => array_map(fn ($s) => [
            'appId' => $s['cand']['id'],
            'number' => $s['cand']['number'] ?? null,
            'plate' => $s['cand']['plate'] ?? null,
            'date' => $s['cand']['date'] ?? null,
            'address' => $s['cand']['address'] ?? null,
            'status' => $s['cand']['status'] ?? null,
            'externalId' => $s['cand']['externalId'] ?? null,
            'score' => $s['score'],
            'signals' => $s['signals'],
        ], $listed),
    ];
}
