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
        $fn('search_legal', 'Przeszukuje teksty aktów prawnych (prawo o ruchu drogowym, kodeks wykroczeń, ustawa o strażach '
            . 'gminnych…) i zwraca pasujące artykuły. Użyj ZAWSZE zanim podasz podstawę prawną — nie cytuj przepisów z pamięci.',
            ['query' => $str('Hasła kluczowe, np. „zatrzymanie pojazdu na chodniku”'),
             'act' => $str('Opcjonalnie id aktu (z listy w prompcie), by zawęzić szukanie')], ['query']),
        $fn('get_legal_provision', 'Pobiera pełną treść konkretnego artykułu aktu prawnego.',
            ['act' => $str('Id aktu z listy w prompcie'), 'article' => $str('Numer artykułu, np. „49” lub „92a”')], ['act', 'article']),
        $fn('list_my_reports', 'Lista zgłoszeń zalogowanego użytkownika (najnowsze pierwsze), w skrócie. Użyj do pytań '
            . 'o „moje zgłoszenia”, statusy, numery spraw.',
            ['status' => $str('all (domyślnie, bez szkiców), sendable, allWithDrafts lub konkretny status, np. confirmed-waiting'),
             'search' => $str('Opcjonalny tekst: tablica, numer zgłoszenia, adres, data d.mm.rrrr, nazwa jednostki'),
             'limit' => ['type' => 'integer', 'description' => 'Liczba zgłoszeń (domyślnie 10, max 25)']]),
        $fn('get_my_report', 'Szczegóły jednego zgłoszenia zalogowanego użytkownika po jego id (z list_my_reports).',
            ['report_id' => $str('Id zgłoszenia')], ['report_id']),
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
    $hits = \assistant\corpus\search(\assistant\corpus\knowledge(), $query, 4);
    if (!$hits) return ['results' => [], 'note' => 'Brak informacji w bazie wiedzy.'];
    return ['results' => array_map(fn($h) => ['source' => $h['source'], 'title' => $h['title'], 'text' => clip($h['text'], 1800)], $hits)];
}

function actTitle(string $id): ?string {
    foreach (\assistant\corpus\acts() as $a) if ($a['id'] === $id) return $a['short'] ?: $a['title'];
    return null;
}

function searchLegal(string $query, ?string $actId): array {
    if ($actId !== null && actTitle($actId) === null) return ['error' => "Nieznany akt: $actId"];
    $hits = \assistant\corpus\search(\assistant\corpus\legal($actId), $query, 4);
    if (!$hits) return ['results' => [], 'note' => 'Nie znaleziono pasujących przepisów w zapisanych aktach. Nie podawaj podstawy prawnej z pamięci.'];
    return ['results' => array_map(fn($h) => ['act' => $h['source'], 'actTitle' => actTitle($h['source']),
        'article' => $h['title'], 'text' => clip($h['text'], 2500)], $hits)];
}

function legalProvision(string $actId, string $article): array {
    if (actTitle($actId) === null) return ['error' => "Nieznany akt: $actId"];
    $c = \assistant\corpus\article($actId, $article);
    if (!$c) return ['error' => "Brak artykułu $article w zapisanym tekście aktu $actId"];
    return ['act' => $actId, 'actTitle' => actTitle($actId), 'article' => $c['title'], 'text' => clip($c['text'], 5000)];
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
        'status' => $a->status,
        'statusName' => $STATUSES[$a->status]->name ?? $a->status,
        'date' => isset($a->date) ? $a->getDate() : null,
        'address' => $a->address->address ?? null,
        'plate' => $a->carInfo->plateId ?? null,
        'category' => $a->getCategory()?->getFormal(),
        'recipient' => $sm,
        'caseNumber' => ($a->externalId ?? '') !== '' ? $a->externalId : null,
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
        ];
    }
    return array_filter($out, fn($v) => $v !== null);
}

function listReports(\user\User $user, string $status, string $search, int $limit): array {
    $limit = max(1, min($limit, 25));
    $apps = \user\apps($user, ...\user\appsQueryFromParams(['status' => $status, 'search' => $search, 'limit' => $limit], 25));
    return ['count' => count($apps), 'reports' => array_map(fn($a) => summarize($a), $apps)];
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
