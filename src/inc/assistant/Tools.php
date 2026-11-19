<?PHP namespace assistant\tools;

require_once(__DIR__ . '/Corpus.php');

/**
 * Narzędzia (skille) asystenta wołane przez model w pętli tool-calling (Assistant.php).
 *
 * Zasady bezpieczeństwa: tożsamość użytkownika pochodzi WYŁĄCZNIE z sesji (parametr $user), nigdy z argumentów modelu;
 * zgłoszenie cudzego użytkownika jest raportowane jak nieistniejące (jak w MCP). Wyniki są przycinane do RESULT_MAX_CHARS,
 * żeby jedno narzędzie nie zalało kontekstu. Błąd narzędzia wraca do modelu jako {"error": "..."}, nie wyjątek.
 */

const RESULT_MAX_CHARS = 6000;
const CONTACT_URL = '/kontakt.html';

/** Definicje w formacie OpenAI `tools` (chat.completions). Skille instrukcyjne dopisuje się plikami w skills/. */
function definitions(): array {
    $fn = fn(string $name, string $description, array $properties = [], array $required = []) => [
        'type' => 'function',
        'function' => ['name' => $name, 'description' => $description, 'parameters' => [
            'type' => 'object', 'properties' => (object)$properties, 'required' => $required, 'additionalProperties' => false]],
    ];
    $str = fn(string $d) => ['type' => 'string', 'description' => $d];

    $tools = [
        $fn('search_knowledge', 'Przeszukuje bazę wiedzy serwisu (FAQ, wiki, praktyka). Użyj do pytań o działanie serwisu, '
            . 'współpracę ze służbami, typowe problemy. Zwraca najlepiej pasujące fragmenty.',
            ['query' => $str('Hasła kluczowe lub pytanie, po polsku')], ['query']),
        $fn('search_legal', 'Przeszukuje teksty aktów prawnych z listy w prompcie (ustawy i rozporządzenia) i zwraca '
            . 'pasujące artykuły lub paragrafy. Użyj ZAWSZE zanim podasz podstawę prawną — nie cytuj przepisów z pamięci. '
            . 'Pytania o wysokość mandatu: act = „taryfikator” (pozycje „Lp. N”: naruszenie, kwota, kwalifikacja).',
            ['query' => $str('Hasła kluczowe, np. „zatrzymanie pojazdu na chodniku”'),
             'act' => $str('Opcjonalnie id aktu (z listy w prompcie), by zawęzić szukanie')], ['query']),
        $fn('get_legal_provision', 'Pobiera pełną treść konkretnego artykułu ustawy lub paragrafu rozporządzenia.',
            ['act' => $str('Id aktu z listy w prompcie'),
             'article' => $str('Numer artykułu („49”, „92a”, „60¹”), paragrafu rozporządzenia („§ 5”) lub pozycji taryfikatora („Lp. 165”), opcjonalnie z częścią: '
                 . '„2 pkt 29”, „130a ust. 5”, „96 § 1a”, „§ 2 ust. 1”')], ['act', 'article']),
        $fn('list_my_reports', 'Lista zgłoszeń zalogowanego użytkownika (najnowsze pierwsze), w skrócie. Użyj do pytań '
            . 'o „moje zgłoszenia”, statusy, numery spraw. Bez `search` wynik zawiera `total` i `byStatus` (prawdziwa liczba zgłoszeń '
            . 'i podział na statusy) — liczby podawaj z nich, nie z `count` (to tylko długość zwróconej listy, obciętej do `limit`).',
            ['status' => $str('all (domyślnie, bez szkiców), sendable, allWithDrafts lub konkretny status, np. confirmed-waiting'),
             'search' => $str('Opcjonalnie: tablica, numer zgłoszenia UD, numer sprawy służby, adres, data d.mm.rrrr, nazwa jednostki. '
                . 'Kilka fraz oddziel przecinkami (np. całą listę tablic z pisma naraz) — zwróci zgłoszenia pasujące do którejkolwiek. '
                . 'Tablice w UD są zapisane bez spacji; spacje i myślniki są pomijane automatycznie.'),
             'limit' => ['type' => 'integer', 'description' => 'Liczba zgłoszeń (domyślnie 10, max 25)']]),
        $fn('get_my_report', 'Szczegóły jednego zgłoszenia zalogowanego użytkownika po jego id (z list_my_reports).',
            ['report_id' => $str('Id zgłoszenia')], ['report_id']),
        $fn('report_stats', 'Liczy zgłoszenia użytkownika w danym statusie — także historycznie (po historii zmian statusów) i w danym roku. '
            . 'Użyj do pytań „ile kiedykolwiek miało status…”, „ile mandatów w tym roku”. Bez `ever` i `year` liczy status obecny. '
            . 'Z `year` liczy zgłoszenia, którym w tym roku NADANO ten status. Historia statusów może nie obejmować najstarszych zgłoszeń.',
            ['status' => $str('Id statusu: ' . implode(', ', array_map(fn($id, $s) => "$id ({$s->name})", array_keys($GLOBALS['STATUSES'] ?? []), $GLOBALS['STATUSES'] ?? []))),
             'ever' => ['type' => 'boolean', 'description' => 'true = kiedykolwiek miało ten status (nawet jeśli już go nie ma)'],
             'year' => ['type' => 'integer', 'description' => 'Rok nadania statusu, np. 2026']], ['status']),
        $fn('resolution_time', 'Liczy, ile czasu mija od wysłania zgłoszenia do pierwszej odpowiedzi służby (Potwierdzone, Mandat, Pouczenie, '
            . 'Zignorowane) — średnia, mediana, najkrócej, najdłużej, w dniach, dla wszystkich zgłoszeń użytkownika naraz. '
            . 'Użyj do pytań „ile czasu od zgłoszenia do statusu końcowego / odpowiedzi”. Bez `year` liczy wszystkie lata.',
            ['year' => ['type' => 'integer', 'description' => 'Tylko zgłoszenia wysłane w tym roku, np. 2026']]),
        $fn('photos_package', 'Przygotowuje paczkę ZIP ze zdjęciami wskazanych zgłoszeń użytkownika (np. do uzupełnienia sprawy na '
            . 'wezwanie służby). Zwraca `url` — wstaw go do odpowiedzi jako link Markdown „Pobierz paczkę ZIP”. '
            . 'Id zgłoszeń weź z list_my_reports (pole id).',
            ['report_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Id zgłoszeń (max ' . PHOTOS_PACKAGE_MAX . ')']],
            ['report_ids']),
        $fn('escalate_to_contact', 'Wywołaj, gdy NIE potrafisz rzetelnie odpowiedzieć (brak informacji w bazie wiedzy i '
            . 'przepisach, błąd serwisu, sprawa indywidualna, prośba o kontakt z człowiekiem). Pokaże użytkownikowi '
            . 'przycisk przejścia na stronę kontaktową.',
            ['reason' => $str('Krótko: czego nie udało się ustalić')], ['reason']),
    ];
    if (\assistant\corpus\skills())
        $tools[] = $fn('load_skill', 'Ładuje instrukcję skilla (krok po kroku) do zadań złożonych, np. przygotowania pisma. '
            . 'Dostępne skille są wymienione w prompcie.',
            ['name' => ['type' => 'string', 'enum' => array_keys(\assistant\corpus\skills()), 'description' => 'Nazwa skilla']], ['name']);
    return $tools;
}

/** @return array<string,mixed> wynik do przekazania modelowi jako treść narzędzia (JSON) */
function execute(string $name, array $args, \user\User $user): array {
    try {
        return match ($name) {
            'search_knowledge' => searchKnowledge((string)($args['query'] ?? '')),
            'search_legal' => searchLegal((string)($args['query'] ?? ''), isset($args['act']) ? (string)$args['act'] : null),
            'get_legal_provision' => legalProvision((string)($args['act'] ?? ''), (string)($args['article'] ?? '')),
            'list_my_reports' => listReports($user, (string)($args['status'] ?? 'all'), (string)($args['search'] ?? ''), (int)($args['limit'] ?? 10)),
            'report_stats' => reportStats($user, (string)($args['status'] ?? ''), (bool)($args['ever'] ?? false), isset($args['year']) ? (int)$args['year'] : null),
            'resolution_time' => resolutionTime($user, isset($args['year']) ? (int)$args['year'] : null),
            'photos_package' => photosPackage($user, (array)($args['report_ids'] ?? [])),
            'get_my_report' => getReport($user, (string)($args['report_id'] ?? '')),
            'load_skill' => loadSkill((string)($args['name'] ?? '')),
            'escalate_to_contact' => ['contactUrl' => CONTACT_URL, 'reason' => (string)($args['reason'] ?? ''),
                'instruction' => 'Napisz użytkownikowi krótko, że w tej sprawie najlepiej skontaktować się przez stronę kontaktową.'],
            default => ['error' => "Nieznane narzędzie: $name"],
        };
    } catch (\Throwable $e) {
        log_error("assistant tool $name: " . $e->getMessage(), $e);
        return ['error' => 'Narzędzie chwilowo niedostępne.'];
    }
}

function clip(string $text, int $max = 3000): string {
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . ' […]' : $text;
}

function searchKnowledge(string $query): array {
    $hits = \assistant\corpus\search(\assistant\corpus\knowledge(), $query, 3);
    if (!$hits) return ['results' => [], 'note' => 'Brak informacji w bazie wiedzy.'];
    // 3 × 1600 mieści się w RESULT_MAX_CHARS; link „Źródło:” (fragmenty z wiki, tools/wiki-knowledge.php) zostaje po skróceniu.
    return ['results' => array_map(function ($h) {
        $text = $h['text'];
        $source = preg_match('/\n(Źródło: \S+)\s*$/u', $text, $m) ? $m[1] : null;
        if ($source !== null) $text = mb_substr($text, 0, -mb_strlen($m[0]));
        return ['source' => $h['source'], 'title' => $h['title'], 'text' => clip($text, 1600) . ($source !== null ? "\n$source" : '')];
    }, $hits)];
}

function actTitle(string $id): ?string {
    foreach (\assistant\corpus\acts() as $a) if ($a['id'] === $id) return $a['short'] ?: $a['title'];
    return null;
}

function searchLegal(string $query, ?string $actId): array {
    if ($actId !== null && actTitle($actId) === null) return ['error' => "Nieznany akt: $actId"];
    // Zapytanie o numer („art. 85a”, „art 85a kw”) trafia wprost w artykuł o tym numerze — w każdym akcie z listy (albo w `act`),
    // bo wyszukiwanie po słowach gubi samą liczbę i model szukał wtedy w niewłaściwym akcie.
    $hits = [];
    if (preg_match_all('/\bart(?:ykuł\w*)?\.?\s*(\d+[a-z]?(?:[⁰¹²³⁴⁵⁶⁷⁸⁹]+)?)/iu', $query, $m))
        foreach (array_unique($m[1]) as $num)
            foreach (\assistant\corpus\acts() as $act)
                if (($actId === null || $actId === $act['id']) && ($c = \assistant\corpus\article($act['id'], $num)))
                    $hits[] = $c;
    $hits = array_slice($hits, 0, 4);
    foreach (\assistant\corpus\search(\assistant\corpus\legalSearchChunks($actId), $query, 4) as $h)
        if (count($hits) < 4 && !array_filter($hits, fn($x) => $x['source'] === $h['source'] && $x['title'] === $h['title'])) $hits[] = $h;
    if (!$hits) return ['results' => [], 'note' => 'Nie znaleziono pasujących przepisów w zapisanych aktach. Nie podawaj podstawy prawnej z pamięci.'];
    return ['results' => array_map(fn($h) => ['act' => $h['source'], 'actTitle' => actTitle($h['source']),
        'article' => $h['title'], 'text' => clip($h['text'], 1300)], $hits),  // 4 × 1300 mieści się w RESULT_MAX_CHARS
        'note' => 'Teksty są skrócone — pełną treść przepisu pobierz get_legal_provision (podaj article z wyniku). '
            . 'Ten sam numer artykułu bywa w kilku aktach — wybierz akt, o który pyta użytkownik (act w wyniku), a gdy nie wskazał, zapytaj lub omów oba.'];
}

function legalProvision(string $actId, string $article): array {
    if (actTitle($actId) === null) return ['error' => "Nieznany akt: $actId"];
    $c = \assistant\corpus\provision($actId, $article);
    if (!$c) return ['error' => "Brak przepisu $article w zapisanym tekście aktu $actId. Zapisujemy tylko przepisy istotne "
        . 'dla serwisu (np. bez rejestracji pojazdów i praw jazdy) — nie wnioskuj, że taki przepis nie istnieje, i nie cytuj go z pamięci.'];
    $out = ['act' => $actId, 'actTitle' => actTitle($actId), 'article' => $c['title']];
    $parts = isset($c['part']) || mb_strlen($c['text']) <= \assistant\corpus\LONG_UNIT ? [] : \assistant\corpus\parts($c);
    if (!$parts) return $out + ['text' => clip($c['text'], 5000)];
    // Długi artykuł: początek + spis części; model pobiera potrzebną część osobnym wywołaniem.
    // Zmieść się w RESULT_MAX_CHARS: krótki początek + spis części (art. 2 PoRD ma ich ~80).
    return $out + ['text' => clip($c['text'], 400),
        'parts' => array_map(fn($h) => mb_substr($h, 0, 55), array_column($parts, 'head')),
        'note' => 'Artykuł jest długi. Pobierz potrzebną część, podając np. „' . $parts[0]['title'] . '” jako article.'];
}

function loadSkill(string $name): array {
    $skill = \assistant\corpus\skills()[$name] ?? null;
    return $skill ? ['skill' => $name, 'instructions' => clip($skill['body'], 5000)] : ['error' => "Nieznany skill: $name"];
}

/** Skrót zgłoszenia — wyłącznie pola potrzebne do rozmowy (bez zdjęć, danych technicznych i danych innych osób). */
function summarize(\app\Application $a, bool $full = false): array {
    global $STATUSES;
    $sm = null;
    try { $sm = !empty($a->smCity) ? $a->guessSMData()->getName() : null; } catch (\Throwable) {}
    $out = [
        'id' => $a->id,
        'number' => $a->number ?? null,
        'link' => '/app/' . $a->id, // szczegóły zgłoszenia — numer UD zawsze linkuj tym adresem
        'status' => $a->status,
        'statusName' => $STATUSES[$a->status]->name ?? $a->status,
        'date' => isset($a->date) ? $a->getDate() : null,
        'address' => $a->address->address ?? null,
        'plate' => $a->carInfo->plateId ?? null,
        'category' => $a->getCategory()?->getFormal(),
        'recipient' => $sm,
        'caseNumber' => ($a->externalId ?? '') !== '' ? $a->externalId : null,
        'statusChanged' => ($h = $a->statusHistory ?? []) ? array_key_last($h) : null, // data ostatniej zmiany statusu
    ];
    if ($full) {
        $out += [
            'sentDate' => $a->wasSent() ? $a->getSentDate() : null,
            'userComment' => $a->userComment ?? null,
            'privateNote' => ($a->privateComment ?? '') !== '' ? $a->privateComment : null,
            'statusHint' => $STATUSES[$a->status]->action ?? null,
            'law' => $a->getCategory()?->getLaw(),
            'fine' => $a->getCategory()?->getMandate(),
            'demeritPoints' => $a->getCategory()?->getPoints(),
            'statusHistory' => array_values(array_map(fn($date, $h) => ['date' => $date, 'from' => $h->old ?? null, 'to' => $h->new ?? null],
                array_keys($a->statusHistory ?? []), array_values($a->statusHistory ?? []))) ?: null,
        ];
    }
    return array_filter($out, fn($v) => $v !== null);
}

/** Frazy wyszukiwania rozdzielone przecinkiem/średnikiem/nową linią (np. lista tablic wklejona z pisma). */
function searchTerms(string $search): array {
    $terms = preg_split('/[,;\n]+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_slice(array_values(array_unique(array_filter(array_map('trim', $terms), fn($t) => $t !== ''))), 0, 30);
}

/** Tablice w UD są bez spacji i myślników („PO 5CS28” → „PO5CS28”); tylko krótkie frazy z literą i cyfrą, żeby nie psuć adresów. */
function plateForm(string $term): string {
    $plain = str_replace([' ', '-'], '', $term);
    return mb_strlen($plain) <= 8 && preg_match('/^[\p{L}\d ]+$/u', str_replace('-', '', $term)) && preg_match('/\d/', $plain) && preg_match('/\p{L}/u', $plain)
        ? $plain : $term;
}

function listReports(\user\User $user, string $status, string $search, int $limit): array {
    $limit = max(1, min($limit, 25));
    $terms = searchTerms($search);
    $query = fn(string $term) => \user\apps($user, ...\user\appsQueryFromParams(['status' => $status, 'search' => $term, 'limit' => $limit], 25));

    if (!$terms) {
        $apps = $query('');
        $out = ['count' => count($apps), 'reports' => array_map(fn($a) => summarize($a), $apps)];
        if ($status === 'all' || $status === 'allWithDrafts') $out += reportTotals($user, $status === 'allWithDrafts');
        return $out + ['truncated' => ($out['total'] ?? count($apps)) > count($apps)];
    }

    $found = [];
    $notFound = [];
    foreach ($terms as $term) {
        $apps = $query($term);
        if (!$apps && ($alt = plateForm($term)) !== $term) $apps = $query($alt);
        if (!$apps) $notFound[] = $term;
        foreach ($apps as $a) $found[$a->id] ??= $a;
    }
    $total = count($found);
    $found = array_slice($found, 0, 25, true);
    return ['count' => count($found), 'reports' => array_values(array_map(fn($a) => summarize($a), $found)),
        'truncated' => $total > count($found)]
        + (count($terms) > 1 ? ['searched' => count($terms), 'notFound' => $notFound] : []);
}

/** Prawdziwa liczba zgłoszeń i podział na statusy (SQL, bez ładowania zgłoszeń). */
function reportTotals(\user\User $user, bool $withDrafts): array {
    global $STATUSES;
    $byStatus = [];
    foreach (\user\_countAppsByStatus($user->getEmail()) as $status => $cnt) {
        if (!$withDrafts && in_array($status, ['ready', 'draft'], true)) continue;
        $byStatus[$STATUSES[$status]->name ?? $status] = (int)$cnt;
    }
    return ['total' => array_sum($byStatus), 'byStatus' => $byStatus];
}

/** Zliczanie po historii statusów (JSON w bazie: statusHistory = {data: {old, new}}), bez ładowania zgłoszeń. */
function reportStats(\user\User $user, string $status, bool $ever, ?int $year): array {
    global $STATUSES;
    if (!isset($STATUSES[$status])) return ['error' => "Nieznany status: $status"];
    $params = [':email' => $user->getEmail(), ':status' => $status];
    if (!$ever && $year === null) {
        $sql = "select count(*) from applications where email = :email and json_extract(value, '$.status') = :status";
        $basis = 'status obecny';
    } else {
        $yearCond = '';
        if ($year !== null) {
            $yearCond = 'and substr(h.key, 1, 4) = :year';
            $params[':year'] = sprintf('%04d', $year);
        }
        $sql = "select count(*) from applications a where a.email = :email and exists (
            select 1 from json_each(a.value, '$.statusHistory') h where json_extract(h.value, '$.new') = :status $yearCond)";
        $basis = $year !== null ? "nadano status w $year r." : 'kiedykolwiek miało ten status (wg historii)';
    }
    $stmt = \store\prepare($sql);
    $stmt->execute($params);
    return ['status' => $STATUSES[$status]->name ?? $status, 'count' => (int)$stmt->fetchColumn(), 'basis' => $basis]
        + ($ever || $year !== null ? ['note' => 'Historia statusów może nie obejmować najstarszych zgłoszeń.'] : []);
}

const RESPONSE_STATUSES = ['confirmed-sm', 'confirmed-fined', 'confirmed-instructed', 'confirmed-ignored'];

/** Czas od wysłania do pierwszej odpowiedzi służby (pierwszy wpis w statusHistory ze statusem odpowiedzi), dla wszystkich zgłoszeń naraz. */
function resolutionTime(\user\User $user, ?int $year): array {
    global $STATUSES;
    $stmt = \store\prepare("select coalesce(json_extract(value, '$.sent.date'), json_extract(value, '$.date')) as started,
        json_extract(value, '$.statusHistory') as history from applications
        where email = :email and json_extract(value, '$.status') not in ('draft', 'ready', 'confirmed')");
    $stmt->execute([':email' => $user->getEmail()]);
    $days = [];
    $byOutcome = [];
    $pending = 0;
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $start = $row['started'] ? strtotime($row['started']) : false;
        if ($start === false || ($year !== null && (int)date('Y', $start) !== $year)) continue;
        $history = json_decode($row['history'] ?? '[]', true) ?: [];
        ksort($history);
        $first = null;
        foreach ($history as $when => $h) {
            if (in_array($h['new'] ?? null, RESPONSE_STATUSES, true) && ($t = strtotime($when)) !== false) { $first = [$t, $h['new']]; break; }
        }
        if ($first === null) { $pending++; continue; }
        $days[] = max(0, ($first[0] - $start) / 86400);
        $byOutcome[$first[1]] = ($byOutcome[$first[1]] ?? 0) + 1;
    }
    if (!$days) return ['answered' => 0, 'withoutResponse' => $pending, 'note' => 'Brak zgłoszeń z odpowiedzią służby w historii statusów.'];
    sort($days);
    $n = count($days);
    $median = $n % 2 ? $days[intdiv($n, 2)] : ($days[$n / 2 - 1] + $days[$n / 2]) / 2;
    $r = fn(float $d) => round($d, 1);
    $names = [];
    foreach ($byOutcome as $id => $c) $names[$STATUSES[$id]->name ?? $id] = $c;
    return ['answered' => $n, 'withoutResponse' => $pending, 'avgDays' => $r(array_sum($days) / $n), 'medianDays' => $r($median),
        'minDays' => $r($days[0]), 'maxDays' => $r($days[$n - 1]), 'firstResponseBy' => $names, 'year' => $year,
        'note' => 'Od wysłania do pierwszej odpowiedzi służby; zgłoszenia bez odpowiedzi (withoutResponse) nie wchodzą do średniej. '
            . 'Historia statusów może nie obejmować najstarszych zgłoszeń.'];
}

const PHOTOS_PACKAGE_MAX = 30;

/** @return list<\app\Application> zgłoszenia użytkownika o podanych id (cudzych i nieistniejących nie potwierdzamy) */
function ownReports(\user\User $user, array $ids): array {
    $apps = [];
    foreach (array_slice(array_values(array_unique(array_map('strval', $ids))), 0, PHOTOS_PACKAGE_MAX) as $id) {
        try {
            $a = \app\get($id);
        } catch (\Throwable) {
            continue;
        }
        if ($a->email === $user->getEmail() && isset($a->contextImage->url, $a->carImage->url)) $apps[] = $a;
    }
    return $apps;
}

function photosPackage(\user\User $user, array $ids): array {
    $apps = ownReports($user, $ids);
    if (!$apps) return ['error' => 'Nie znaleziono zgłoszeń ze zdjęciami o podanych id.'];
    return ['reports' => count($apps), 'requested' => count($ids),
        'url' => '/api/chat/photos?ids=' . implode(',', array_map(fn($a) => $a->id, $apps)),
        'note' => 'Wstaw url jako link Markdown. Paczka zawiera zdjęcia kontekstu, pojazdu i (jeśli są) trzecie, po jednym folderze na zgłoszenie.'];
}

/** Buduje ZIP (jeden folder na zgłoszenie) w pliku tymczasowym; zwraca jego ścieżkę. */
function photosZip(array $apps): string {
    $path = tempnam(sys_get_temp_dir(), 'udphotos');
    $zip = new \ZipArchive;
    if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true)
        throw new \RuntimeException('Błąd tworzenia archiwum ZIP.');
    foreach ($apps as $a) {
        $a->ensureLocal();
        $dir = str_replace('/', '-', $a->number ?? $a->id) . '/';
        foreach (['a' => $a->contextImage ?? null, 'b' => $a->carImage ?? null, 'c' => $a->thirdImage ?? null] as $suffix => $img) {
            if (isset($img->url) && file_exists(ROOT . $img->url)) $zip->addFile(ROOT . $img->url, $dir . $a->getAppFilename("$suffix.jpg", ''));
        }
    }
    $zip->close();
    return $path;
}

function getReport(\user\User $user, string $reportId): array {
    try {
        $a = \app\get($reportId);
    } catch (\Throwable) {
        return ['error' => "Nie znaleziono zgłoszenia '$reportId'"];
    }
    if ($a->email !== $user->getEmail()) return ['error' => "Nie znaleziono zgłoszenia '$reportId'"]; // nie potwierdzamy cudzych
    return summarize($a, full: true);
}

/**
 * Numery UD → id zgłoszeń z wyniku narzędzia (list_my_reports / get_my_report) — do linkowania numerów w odpowiedzi.
 * @return array<string,string>
 */
function reportIds(array $result): array {
    $out = [];
    foreach ($result['reports'] ?? [$result] as $r) {
        if (is_array($r) && isset($r['id'], $r['number'])) $out[(string)$r['number']] = (string)$r['id'];
    }
    return $out;
}
