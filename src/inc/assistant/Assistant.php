<?PHP namespace assistant;

require_once(__DIR__ . '/Tools.php');
require_once(__DIR__ . '/Attachments.php');

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Asystent AI na stronie: pamięta rozmowę (\chat\ — store.sqlite), odpowiada na podstawie bazy wiedzy, aktów prawnych
 * i zgłoszeń użytkownika (narzędzia z Tools.php) i — gdy nie potrafi pomóc — kieruje na stronę kontaktową.
 * Decyzje i rozszerzanie: docs/adr/0008-chat-assistant.md.
 */

/** Błąd do pokazania użytkownikowi; kod = status HTTP (400 zła wiadomość, 429 limit). */
final class ChatException extends \RuntimeException {}

/** Test seam: podstaw \OpenAI\Testing\ClientFake (jak \vision\VisionClient). */
final class ChatClient {
    private static ?object $override = null;

    public static function set(?object $client): void {
        self::$override = $client;
    }

    public static function get(): object {
        if (self::$override !== null) return self::$override;
        return \OpenAI::factory()
            ->withApiKey(OPENAI_API_KEY)
            ->withProject(OPENAI_PROJECT)
            ->withHttpClient(new \GuzzleHttp\Client(['timeout' => OPENAI_CHAT_TIMEOUT]))
            ->make();
    }
}

/**
 * Kto widzi asystenta (widżet i /api/chat): aktywni i byli patroni — każdy adres z listy Patronite.
 * Załączniki to osobny, węższy warunek: tylko aktywni (attachments\isAllowed).
 */
function isAvailable(\user\User $user): bool {
    $email = $user->getEmail();
    return \patronite\is($email) || \patronite\isFormer($email);
}

const CONTACT_FALLBACK = "Przepraszam, nie mogę teraz odpowiedzieć na to pytanie. "
    . "Napisz do nas przez [stronę kontaktową](" . tools\CONTACT_URL . ") — odpowiemy.";

function buildSystemPrompt(\user\User $user): string {
    $skills = '';
    foreach (corpus\skills() as $s) $skills .= "- {$s['name']}: {$s['description']}\n";
    $acts = '';
    foreach (corpus\acts() as $a) $acts .= "- {$a['id']}: {$a['title']}" . ($a['ref'] ? " ({$a['ref']})" : '') . "\n";
    // Rola i zasady: prompt.md (zwykły tekst, {{data}} i {{kontakt}} podstawiane tutaj); reszta doklejana z korpusu.
    $base = strtr(trim(file_get_contents(__DIR__ . '/prompt.md')), ['{{data}}' => date('Y-m-d'), '{{kontakt}}' => tools\CONTACT_URL]);

    return trim("$base\n\n" . ($acts !== '' ? "AKTY PRAWNE dostępne w narzędziach (parametr act):\n$acts\n" : '')
. ($skills !== '' ? "SKILLE (instrukcje do zadań złożonych; załaduj narzędziem load_skill, gdy pasują do prośby):\n$skills\n" : '')
. (($b = corpus\basics()) !== '' ? "PODSTAWY O SERWISIE:\n$b\n" : ''));
}

/**
 * Wiadomości do modelu: prompt systemowy + ostatnie CHAT_CONTEXT_MESSAGES z zapisanej rozmowy.
 * Powiadomienia systemowe wchodzą jako wiadomości asystenta z prefiksem — użytkownik może o nie dopytać.
 */
function buildMessages(\user\User $user, array $attachments = []): array {
    $messages = [['role' => 'system', 'content' => buildSystemPrompt($user)]];
    $history = \chat\history($user->getEmail(), CHAT_CONTEXT_MESSAGES);
    $last = array_key_last($history);
    foreach ($history as $i => $m) {
        $files = array_column($m['meta']['attachments'] ?? [], 'name');
        $messages[] = match (true) {
            // Bieżąca wiadomość z plikami: model dostaje same pliki (bajtów nie przechowujemy).
            $m['role'] === 'user' && $i === $last && $attachments => ['role' => 'user', 'content' => attachments\contentParts($m['content'], $attachments)],
            $m['role'] === 'user' => ['role' => 'user', 'content' => $m['content'] . ($files ? "\n[Załączone pliki: " . implode(', ', $files) . ' — niedostępne w tej wiadomości]' : '')],
            $m['role'] === 'notification' => ['role' => 'assistant', 'content' => '[Powiadomienie systemowe] ' . $m['content']],
            default => ['role' => 'assistant', 'content' => $m['content']],
        };
    }
    return $messages;
}

/** @return array<string,mixed> */
function completionParams(array $messages, bool $withTools): array {
    $params = ['model' => OPENAI_CHAT_MODEL, 'messages' => $messages];
    // Rodzina „reasoning” ma inny kontrakt parametrów niż klasyczne modele — patrz komentarz w \vision\chat().
    if (preg_match('/^(gpt-5|o1|o3|o4)/', OPENAI_CHAT_MODEL)) {
        $params['max_completion_tokens'] = OPENAI_CHAT_MAX_TOKENS;
        $params['reasoning_effort'] = 'low';
    } else {
        $params['max_tokens'] = OPENAI_CHAT_MAX_TOKENS;
        $params['temperature'] = 0.3;
    }
    if ($withTools) {
        $params['tools'] = tools\definitions();
        $params['tool_choice'] = 'auto';
    }
    return $params;
}

/**
 * Koszt wywołań LLM w USD wg cennika $MODEL_PRICING (USD za 1 mln tokenów); nieznany model = 0.
 * @param array{prompt:int,completion:int} $usage
 */
function costUsd(array $usage, string $model = OPENAI_CHAT_MODEL): float {
    // data.php bywa załadowane w zasięgu lokalnym (require_once z funkcji/loadera), więc $MODEL_PRICING nie zawsze jest globalne.
    if (!isset($GLOBALS['MODEL_PRICING'])) {
        include(__DIR__ . '/../data.php');
        $GLOBALS['MODEL_PRICING'] = $MODEL_PRICING ?? [];
    }
    $p = $GLOBALS['MODEL_PRICING'][$model] ?? null;
    if (!$p) return 0.0;
    return round(($usage['prompt'] * ($p['prompt'] ?? 0) + $usage['completion'] * ($p['completion'] ?? 0)) / 1_000_000, 8);
}

/**
 * Pętla tool-calling. Zwraca [tekst odpowiedzi, użyte narzędzia, flaga kontaktu, tokeny, źródła, liczba rund].
 * Każde wywołanie narzędzia trafia do logu (trace()) ze streszczeniem wyniku; źródła (pliki wiedzy, przepisy,
 * skille) zbierane są do meta wiadomości.
 * @return array{0:string,1:list<string>,2:bool,3:array{prompt:int,completion:int},4:array<string,list<string>>,5:int}
 */
function runModel(\user\User $user, array $messages, string $traceId = ''): array {
    $client = ChatClient::get();
    $used = [];
    $contact = false;
    $usage = ['prompt' => 0, 'completion' => 0];
    $sources = ['knowledge' => [], 'legal' => [], 'skills' => []];

    for ($round = 0; ; $round++) {
        $final = $round >= CHAT_MAX_TOOL_ROUNDS; // limit rund: ostatnie wywołanie bez narzędzi wymusza odpowiedź tekstową
        $params = completionParams($messages, !$final);
        if ($final) $messages[] = ['role' => 'system', 'content' => 'Osiągnięto limit wywołań narzędzi. Odpowiedz teraz na podstawie zebranych danych.'];
        $params['messages'] = $messages;

        $resp = $client->chat()->create($params);
        $usage['prompt'] += $resp->usage->promptTokens ?? 0;
        $usage['completion'] += $resp->usage->completionTokens ?? 0;
        $msg = $resp->choices[0]->message;
        $calls = $msg->toolCalls ?? [];

        if (!$calls || $final) {
            $text = trim((string)($msg->content ?? ''));
            if ($text === '') throw new \RuntimeException('pusta odpowiedź modelu (finish=' . ($resp->choices[0]->finishReason ?? '?') . ')');
            return [$text, $used, $contact, $usage, array_map(fn($l) => array_values(array_unique($l)), array_filter($sources)), $round + 1];
        }

        $messages[] = ['role' => 'assistant', 'content' => $msg->content, 'tool_calls' => array_map(fn($c) => [
            'id' => $c->id, 'type' => 'function',
            'function' => ['name' => $c->function->name, 'arguments' => $c->function->arguments]], $calls)];
        foreach ($calls as $call) {
            $name = $call->function->name;
            $args = json_decode($call->function->arguments ?: '{}', true);
            $result = tools\execute($name, is_array($args) ? $args : [], $user);
            $used[] = $name;
            [$summary, $found] = toolSummary($name, is_array($args) ? $args : [], $result);
            foreach ($found as $kind => $items) array_push($sources[$kind], ...$items);
            trace($traceId, 'runda ' . ($round + 1) . " → $name " . json_encode($args ?: new \stdClass, JSON_UNESCAPED_UNICODE) . " → $summary");
            if ($name === 'escalate_to_contact') $contact = true;
            $messages[] = ['role' => 'tool', 'tool_call_id' => $call->id,
                'content' => tools\clip(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), tools\RESULT_MAX_CHARS)];
        }
    }
}

/**
 * Obsługuje wiadomość użytkownika: waliduje, limituje, zapisuje, pyta model, zapisuje odpowiedź.
 * Awaria modelu NIE rzuca — użytkownik dostaje odpowiedź kierującą na kontakt (zapisaną w rozmowie z meta.error),
 * a kredyty zdjęć zarezerwowane na załączniki wracają.
 * $attachments: [{name, data: "data:<mime>;base64,…"}] — tylko dla patronów; każdy plik (obraz i PDF) zużywa kredyt zdjęć (\quota).
 * @return array{id:int,role:string,content:string,html:string,meta:array<string,mixed>,createdAt:int}
 * @throws ChatException pusta/za długa wiadomość lub zły załącznik (400/415), brak patronatu (403), limit (429)
 * @throws \quota\QuotaExceededException wyczerpane kredyty zdjęć (402)
 */
function reply(\user\User $user, string $text, array $attachments = []): array {
    $email = $user->getEmail();
    $text = trim($text);
    if (mb_strlen($text) > CHAT_MAX_MESSAGE_CHARS)
        throw new ChatException('Wiadomość jest za długa (max ' . CHAT_MAX_MESSAGE_CHARS . ' znaków).', 400);
    if ($attachments && !attachments\isAllowed($user))
        throw new ChatException('Załączniki są dostępne dla patronów: https://patronite.pl/uprzejmiedonosze', 403);
    $items = attachments\decode($attachments);
    if ($text === '' && !$items) throw new ChatException('Napisz wiadomość.', 400);
    if ($text === '') $text = 'Zobacz załączone pliki.';
    if (\chat\userMessagesSince($email, time() - CHAT_RATE_WINDOW) >= CHAT_RATE_MAX)
        throw new ChatException('Zadałeś/aś dużo pytań w krótkim czasie. Spróbuj za chwilę lub napisz przez stronę kontaktową.', 429);

    $charged = attachments\charge($user, $items); // QuotaExceededException → nic nie zapisano
    \chat\add($email, 'user', $text, $items ? ['attachments' => attachments\describe($items)] : []);

    $meta = [];
    $traceId = substr(bin2hex(random_bytes(4)), 0, 6);
    $started = microtime(true);
    trace($traceId, 'pytanie (' . mb_strlen($text) . ' zn.' . ($items ? ', załączniki: ' . implode(', ', array_column(attachments\describe($items), 'name')) : '')
        . '): ' . oneLine($text, 500));
    try {
        $messages = buildMessages($user, $items);
        $promptId = promptId($messages[0]['content']);
        trace($traceId, "prompt $promptId (" . mb_strlen($messages[0]['content']) . ' zn.; akty: ' . implode(',', array_column(corpus\acts(), 'id'))
            . '; skille: ' . (implode(',', array_keys(corpus\skills())) ?: '—') . '; historia: ' . (count($messages) - 1) . ' wiad.; model ' . OPENAI_CHAT_MODEL . ')');
        [$answer, $used, $contact, $usage, $sources, $rounds] = runModel($user, $messages, $traceId);
        $meta = ['model' => OPENAI_CHAT_MODEL, 'tools' => $used, 'tokens' => $usage, 'costUsd' => costUsd($usage),
            'prompt' => $promptId, 'trace' => $traceId] + $sources + ($contact ? ['contact' => true] : []);
        trace($traceId, "odpowiedź ($rounds rund, " . count($used) . ' narzędzi, ' . $usage['prompt'] . '+' . $usage['completion'] . ' tok., $'
            . sprintf('%.4f', $meta['costUsd']) . ', ' . round(microtime(true) - $started, 1) . ' s, ' . mb_strlen($answer) . ' zn.'
            . ($contact ? ', kontakt' : '') . '): ' . oneLine($answer, 300));
        \telemetry\log('chat_reply', null, ['vendor' => 'openai-chat']);
    } catch (\Throwable $e) {
        trace($traceId, 'błąd: ' . $e->getMessage());
        log_error('assistant: ' . $e->getMessage(), $e);
        attachments\release($user, $charged); // awaria po naszej stronie nie zjada kredytów
        $answer = CONTACT_FALLBACK;
        $meta = ['error' => true, 'contact' => true];
    }

    $id = \chat\add($email, 'assistant', $answer, $meta);
    return present(['id' => $id, 'role' => 'assistant', 'content' => $answer, 'meta' => $meta, 'createdAt' => time(), 'unread' => false]);
}

/**
 * Linia śladu generowania odpowiedzi: `assistant <trace>: …` (filtr w Papertrail: „assistant <trace>”).
 * log_info: dev/staging tak, prod nie (treść pytań to dane użytkownika; na prod zostaje podsumowanie w meta wiadomości).
 */
function trace(string $traceId, string $line): void {
    log_info("assistant $traceId: $line");
}

/** Tekst w jednej linii logu (Logger dzieli wpisy po \n), przycięty. */
function oneLine(string $text, int $max): string {
    $text = str_replace(["\r\n", "\n", "\r"], ' ⏎ ', trim($text));
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
}

/** Wersja promptu systemowego: skrót treści bez daty (data zmienia się codziennie, reszta — przy zmianie kodu/wiedzy). */
function promptId(string $prompt): string {
    return substr(sha1(preg_replace('/^Dzisiejsza data: .*$/mu', '', $prompt)), 0, 8);
}

/**
 * Streszczenie wyniku narzędzia do logu + źródła do meta. Ze zgłoszeń tylko id/numery (bez adresów i tablic).
 * @return array{0:string,1:array<string,list<string>>}
 */
function toolSummary(string $name, array $args, array $result): array {
    if (isset($result['error'])) return ['błąd: ' . $result['error'], []];
    switch ($name) {
        case 'search_knowledge':
            $hits = array_map(fn($r) => "{$r['source']}: {$r['title']}", $result['results'] ?? []);
            return [$hits ? implode(' | ', $hits) : 'brak wyników', ['knowledge' => $hits]];
        case 'search_legal':
            $hits = array_map(fn($r) => "{$r['act']} {$r['article']}", $result['results'] ?? []);
            return [$hits ? implode(' | ', $hits) : 'brak wyników', ['legal' => $hits]];
        case 'get_legal_provision':
            $ref = "{$result['act']} {$result['article']}";
            return [$ref . (isset($result['parts']) ? ' (spis ' . count($result['parts']) . ' części)' : ''), ['legal' => [$ref]]];
        case 'load_skill':
            return ["skill {$result['skill']}", ['skills' => [$result['skill']]]];
        case 'list_my_reports':
            return [($result['count'] ?? 0) . ' zgłoszeń: ' . implode(', ', array_map(fn($r) => $r['number'] ?? $r['id'], $result['reports'] ?? [])), []];
        case 'get_my_report':
            return ['zgłoszenie ' . ($result['number'] ?? $result['id'] ?? '?') . ' (' . ($result['status'] ?? '?') . ')', []];
        case 'escalate_to_contact':
            return ['kontakt: ' . ($args['reason'] ?? ''), []];
        default:
            return ['ok', []];
    }
}

/** Dodaje do wiadomości pole `html` (bezpieczny Markdown) i adres kontaktu, gdy asystent go zasugerował. */
function present(array $message): array {
    $message['html'] = $message['role'] === 'user' ? '' : renderMarkdown($message['content']);
    if (!empty($message['meta']['contact'])) $message['meta']['contactUrl'] = tools\CONTACT_URL;
    // szczegóły kosztowe i ślad (prompt, źródła) zostają w bazie
    unset($message['meta']['tokens'], $message['meta']['model'], $message['meta']['costUsd'], $message['meta']['prompt'],
        $message['meta']['trace'], $message['meta']['knowledge'], $message['meta']['legal'], $message['meta']['skills']);
    return $message;
}

/** Markdown → HTML bez surowego HTML-a i niebezpiecznych linków (odpowiedź modelu to niezaufane dane). */
function renderMarkdown(string $markdown): string {
    static $converter = null;
    if (!$converter) {
        $env = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new AutolinkExtension());
        $converter = new MarkdownConverter($env);
    }
    return trim((string)$converter->convert($markdown));
}
