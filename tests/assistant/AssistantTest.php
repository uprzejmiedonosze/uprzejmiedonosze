<?php

namespace UprzejmieDonosze\Tests\Assistant;

require_once __DIR__ . '/../../export/inc/assistant/Assistant.php';

use app\Application;
use assistant\ChatClient;
use assistant\ChatException;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use UprzejmieDonosze\Tests\DatabaseTestCase;
use user\User;

class AssistantTest extends DatabaseTestCase
{
    private const EMAIL = 'assistant-test@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        \assistant\corpus\setRoot(__DIR__ . '/../fixtures/assistant');
    }

    protected function tearDown(): void
    {
        ChatClient::set(null);
        \assistant\corpus\setRoot(null);
        parent::tearDown();
    }

    private function user(string $email = self::EMAIL): User
    {
        $_SESSION['user_email'] = $email;
        $_SESSION['user_id'] = 'phpunit-user-id';
        $user = new User();
        $user->email = $email;
        $user->data->email = $email;
        $user->data->name = 'Test User';
        $user->data->address = 'Testowa 1, Szczecin';
        \user\save($user);
        return $user;
    }

    private static function text(string $content): CreateResponse
    {
        return CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]]);
    }

    private static function toolCall(string $name, array $args, string $id = 'call_1'): CreateResponse
    {
        return CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]],
        ]], 'finish_reason' => 'tool_calls']]]);
    }

    public function testPlainAnswerIsSavedAndRendered(): void
    {
        ChatClient::set(new ClientFake([self::text('Serwis jest **bezpłatny**.')]));
        $reply = \assistant\reply($this->user(), 'Ile kosztuje serwis?');

        $this->assertSame('assistant', $reply['role']);
        $this->assertStringContainsString('<strong>bezpłatny</strong>', $reply['html']);
        $this->assertSame(['user', 'assistant'], array_column(\chat\history(self::EMAIL), 'role'));
        $this->assertArrayNotHasKey('tokens', $reply['meta']); // koszty zostają w bazie
        $this->assertArrayNotHasKey('costUsd', $reply['meta']);

        // gpt-5-mini: 10 tokenów wejścia po 0.25 i 5 wyjścia po 2.00 USD / 1 mln
        $stored = \chat\history(self::EMAIL)[1]['meta'];
        $this->assertSame(['prompt' => 10, 'completion' => 5], $stored['tokens']);
        $this->assertEqualsWithDelta(0.0000125, $stored['costUsd'], 1e-9);
    }

    public function testToolRoundTripFeedsResultBackToModel(): void
    {
        $fake = new ClientFake([self::toolCall('search_legal', ['query' => 'zatrzymanie na chodniku']), self::text('Zobacz Art. 49 PoRD.')]);
        ChatClient::set($fake);
        $reply = \assistant\reply($this->user(), 'Czy mogę parkować na chodniku?');

        $this->assertSame('Zobacz Art. 49 PoRD.', $reply['content']);
        $stored = \chat\history(self::EMAIL)[1]['meta'];
        $this->assertSame(['search_legal'], $stored['tools']);
        // ślad generowania: wersja promptu, id śladu i źródła — w bazie, nie w odpowiedzi do przeglądarki
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $stored['prompt']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{6}$/', $stored['trace']);
        $this->assertContains('prd Art. 49', $stored['legal']);
        foreach (['prompt', 'trace', 'legal', 'knowledge', 'skills'] as $key) $this->assertArrayNotHasKey($key, $reply['meta']);
        $fake->chat()->assertSent(function (string $method, array $params) {
            static $n = 0;
            if (++$n < 2) return true;
            $tool = end($params['messages']);
            return $tool['role'] === 'tool' && str_contains($tool['content'], 'Art. 49');
        });
    }

    public function testToolSummaryCollectsSourcesWithoutReportDetails(): void
    {
        [$log, $src] = \assistant\toolSummary('search_knowledge', ['query' => 'x'],
            ['results' => [['source' => 'wiki-faq', 'title' => 'Czy będę anonimowa?', 'text' => '…']]]);
        $this->assertSame('wiki-faq: Czy będę anonimowa?', $log);
        $this->assertSame(['knowledge' => ['wiki-faq: Czy będę anonimowa?']], $src);
        $this->assertSame(['skill odwolanie', ['skills' => ['odwolanie']]],
            \assistant\toolSummary('load_skill', ['name' => 'odwolanie'], ['skill' => 'odwolanie', 'instructions' => '…']));
        [$log] = \assistant\toolSummary('list_my_reports', [], ['count' => 1, 'reports' => [['id' => 'abc', 'number' => 'UD/1/2', 'address' => 'ul. Tajna 1', 'plate' => 'WX123']]]);
        $this->assertSame('1 zgłoszeń: UD/1/2', $log);
        $this->assertSame('błąd: Nieznany skill: x', \assistant\toolSummary('load_skill', [], ['error' => 'Nieznany skill: x'])[0]);
        $this->assertSame(\assistant\promptId("A\nDzisiejsza data: 2026-01-01.\nB"), \assistant\promptId("A\nDzisiejsza data: 2026-11-16.\nB"));
    }

    public function testEscalateToContactSetsContactUrl(): void
    {
        ChatClient::set(new ClientFake([self::toolCall('escalate_to_contact', ['reason' => 'brak danych']), self::text('Napisz do nas.')]));
        $reply = \assistant\reply($this->user(), 'Co z moją sprawą w sądzie?');
        $this->assertSame('/kontakt.html', $reply['meta']['contactUrl']);
    }

    public function testModelFailureFallsBackToContactAndIsSaved(): void
    {
        ChatClient::set(new ClientFake([])); // brak zakolejkowanej odpowiedzi => wyjątek
        $reply = \assistant\reply($this->user(), 'Pytanie');
        $this->assertStringContainsString('/kontakt.html', $reply['content']);
        $this->assertTrue($reply['meta']['error']);
        $this->assertSame('/kontakt.html', $reply['meta']['contactUrl']);
        $this->assertCount(2, \chat\history(self::EMAIL));
    }

    public function testToolLoopIsBoundedByMaxRounds(): void
    {
        $calls = [];
        for ($i = 0; $i < CHAT_MAX_TOOL_ROUNDS; $i++) $calls[] = self::toolCall('search_knowledge', ['query' => 'x'], "c$i");
        $calls[] = self::text('Koniec.');
        $fake = new ClientFake($calls);
        ChatClient::set($fake);
        $this->assertSame('Koniec.', \assistant\reply($this->user(), 'Pętla?')['content']);
    }

    public function testCostSumsAllToolRoundsAndIgnoresUnknownModel(): void
    {
        $this->assertSame(0.0, \assistant\costUsd(['prompt' => 1000, 'completion' => 1000], 'nieznany-model'));
        $this->assertEqualsWithDelta(0.00225, \assistant\costUsd(['prompt' => 1000, 'completion' => 1000], 'gpt-5-mini'), 1e-9);
    }

    public function testRejectsEmptyAndTooLongMessages(): void
    {
        foreach (['   ', str_repeat('a', CHAT_MAX_MESSAGE_CHARS + 1)] as $text) {
            try {
                \assistant\reply($this->user(), $text);
                $this->fail('oczekiwano ChatException');
            } catch (ChatException $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
        $this->assertSame([], \chat\history(self::EMAIL)); // nic nie zapisano
    }

    public function testRateLimit(): void
    {
        for ($i = 0; $i < CHAT_RATE_MAX; $i++) \chat\add(self::EMAIL, 'user', "q$i");
        $this->expectException(ChatException::class);
        $this->expectExceptionCode(429);
        \assistant\reply($this->user(), 'jeszcze jedno');
    }

    public function testAnswerHtmlIsSanitized(): void
    {
        $html = \assistant\renderMarkdown("<script>alert(1)</script>\n\n[x](javascript:alert(1)) [ok](/app/list)");
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('href="/app/list"', $html);
    }

    public function testPromptCarriesBasicsActsAndSkillsButNotArticles(): void
    {
        $prompt = \assistant\systemPrompt($this->user());
        $this->assertStringStartsWith('Jesteś Uprzejmym Asystentem', $prompt); // prompt.md
        $this->assertStringContainsString('Dzisiejsza data: ' . date('Y-m-d'), $prompt);
        $this->assertStringNotContainsString('{{', $prompt);
        $this->assertStringContainsString('bezpłatny', $prompt);       // podstawy.md
        $this->assertStringContainsString('prd: Prawo o ruchu drogowym', $prompt);
        $this->assertStringContainsString('odwolanie:', $prompt);       // skill: nazwa + opis
        $this->assertStringNotContainsString('Krok 1: zbierz', $prompt); // treść skilla dopiero przez load_skill
        $this->assertStringNotContainsString('szerokość chodnika', $prompt); // przepisy tylko przez narzędzia
    }

    public function testContextContainsNotificationsAndIsCapped(): void
    {
        $user = $this->user();
        \chat\notify(self::EMAIL, 'Zgłoszenie UD/1 zostało wysłane');
        for ($i = 0; $i < CHAT_CONTEXT_MESSAGES + 5; $i++) \chat\add(self::EMAIL, 'user', "q$i");
        $messages = \assistant\buildMessages($user);
        $this->assertCount(CHAT_CONTEXT_MESSAGES + 1, $messages); // + system
        $this->assertSame('system', $messages[0]['role']);

        \chat\removeAllForEmail(self::EMAIL);
        \chat\notify(self::EMAIL, 'Zgłoszenie UD/1 zostało wysłane');
        $this->assertStringStartsWith('[Powiadomienie systemowe]', \assistant\buildMessages($user)[1]['content']);
    }

    public function testToolDefinitionsListSkillsFromFiles(): void
    {
        $load = array_values(array_filter(\assistant\tools\definitions(), fn($t) => $t['function']['name'] === 'load_skill'));
        $this->assertSame(['odwolanie'], $load[0]['function']['parameters']['properties']->name['enum']);
        $this->assertEquals(['instructions' => 'Krok 1: zbierz numer zgłoszenia. Krok 2: pobierz przepisy narzędziem search_legal.', 'skill' => 'odwolanie'],
            array_intersect_key(\assistant\tools\execute('load_skill', ['name' => 'odwolanie'], $this->user()), ['skill' => 1, 'instructions' => 1]));
    }

    public function testGetMyReportNeverRevealsAnotherUsersReport(): void
    {
        $owner = $this->user('owner@example.com');
        $app = Application::withUser($owner);
        $app->statusHistory = [];
        $app->comments = [];
        $app->extensions = [];
        $app->date = '2026-05-07T10:00:00';
        $app->carInfo = new \JSONObject();
        $app->carInfo->plateId = 'ZS12345';
        $app->address = new \JSONObject();
        $app->address->address = 'Testowa 1, Szczecin';
        $app->status = 'confirmed';
        $app = \app\save($app);

        $mine = \assistant\tools\execute('get_my_report', ['report_id' => $app->id], $owner);
        $this->assertSame('ZS12345', $mine['plate']);

        $other = \assistant\tools\execute('get_my_report', ['report_id' => $app->id], $this->user());
        $this->assertArrayHasKey('error', $other);
        $this->assertArrayNotHasKey('plate', $other);
        $missing = \assistant\tools\execute('get_my_report', ['report_id' => 'nieistniejace'], $this->user());
        $this->assertStringStartsWith('Nie znaleziono zgłoszenia', $other['error']);
        $this->assertStringStartsWith('Nie znaleziono zgłoszenia', $missing['error']); // cudze == nieistniejące

        $list = \assistant\tools\execute('list_my_reports', [], $this->user());
        $this->assertSame(0, $list['count']);
    }

    public function testUnknownToolAndBadActAreReportedNotThrown(): void
    {
        $this->assertArrayHasKey('error', \assistant\tools\execute('drop_tables', [], $this->user()));
        $this->assertArrayHasKey('error', \assistant\tools\execute('search_legal', ['query' => 'x', 'act' => '../x'], $this->user()));
    }
}
