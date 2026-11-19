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
        $this->assertSame(\assistant\promptId("A\nRozmówca: Ola, płeć: kobieta.\nB"), \assistant\promptId("A\nRozmówca: Jan, płeć: mężczyzna.\nB")); // dane rozmówcy nie zmieniają wersji promptu
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
        $prompt = \assistant\buildSystemPrompt($this->user());
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

        \chat\archive(self::EMAIL); // „Nowa rozmowa”: stara historia znika z kontekstu modelu
        $this->assertCount(1, \assistant\buildMessages($user));
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

    public function testPromptNamesTheInterlocutor(): void
    {
        $user = $this->user();
        $user->data->name = 'Ola Nowak';
        $this->assertStringContainsString('Rozmówca: Ola, płeć: kobieta', \assistant\buildSystemPrompt($user));
        $user->data->name = 'Jan Nowak';
        $user->data->sex = 'm';
        $this->assertStringContainsString('Rozmówca: Jan, płeć: mężczyzna', \assistant\buildSystemPrompt($user));
        $user->data->name = '';
        $this->assertStringContainsString('imię nieznane, płeć: nieznana', \assistant\buildSystemPrompt($user));
    }

    private function report(User $owner, string $plate, string $address = 'Testowa 1, Szczecin', string $status = 'confirmed', ?string $caseNumber = null): Application
    {
        $app = Application::withUser($owner);
        $app->statusHistory = [];
        $app->comments = [];
        $app->extensions = [];
        $app->date = '2026-05-07T10:00:00';
        $app->carInfo = new \JSONObject();
        $app->carInfo->plateId = $plate;
        $app->address = new \JSONObject();
        $app->address->address = $address;
        $app->status = $status;
        if ($caseNumber !== null) $app->externalId = $caseNumber;
        return \app\save($app);
    }

    public function testListReportsReturnsRealTotalsAndStatusBreakdown(): void
    {
        $owner = $this->user('totals@example.com');
        $this->report($owner, 'PO5CS28');
        $this->report($owner, 'PY7048K', status: 'confirmed-complaint');
        $list = \assistant\tools\execute('list_my_reports', ['limit' => 1], $owner);
        $this->assertSame(1, $list['count']);
        $this->assertSame(2, $list['total']);
        $this->assertTrue($list['truncated']);
        $this->assertSame(1, $list['byStatus']['Zażalone']);
        $this->assertFalse(\assistant\tools\execute('list_my_reports', ['limit' => 25], $owner)['truncated']);
    }

    public function testListReportsSearchesManyPlatesAtOnceIgnoringSpaces(): void
    {
        $owner = $this->user('plates@example.com');
        $this->report($owner, 'PO5CS28');
        $this->report($owner, 'PY7048K');
        $this->report($owner, 'WX1', caseNumber: 'SM/21988/2026');
        $found = \assistant\tools\execute('list_my_reports', ['search' => "PO 5CS28, PY-7048K\nBRAK 999, 21988"], $owner);
        $this->assertSame(3, $found['count']);
        $this->assertSame(['BRAK 999'], $found['notFound']);
        $this->assertSame(4, $found['searched']);
        $plates = array_column($found['reports'], 'plate');
        sort($plates);
        $this->assertSame(['PO5CS28', 'PY7048K', 'WX1'], $plates);
    }

    public function testPlateFormOnlyRewritesPlateLikeTerms(): void
    {
        $this->assertSame('PSZ6875C', \assistant\tools\plateForm('PSZ 6875C'));
        $this->assertSame('Rynek Jeżycki 3', \assistant\tools\plateForm('Rynek Jeżycki 3'));
        $this->assertSame(['a', 'b c'], \assistant\tools\searchTerms("a, b c;\n a"));
    }

    public function testPhotosPackageIsOpenToEveryoneButOwnReportsOnly(): void
    {
        $owner = $this->user('photos@example.com');
        $app = $this->report($owner, 'PO5CS28');
        $result = \assistant\tools\execute('photos_package', ['report_ids' => [$app->id]], $owner); // zgłoszenie bez zdjęć w fixture
        $this->assertStringNotContainsString('patron', $result['error']);   // nie jest już funkcją tylko dla patronów
        $this->assertStringContainsString('Nie znaleziono', $result['error']);
        $this->assertSame([], \assistant\tools\ownReports($this->user(), [$app->id])); // cudze zgłoszenie nie trafi do paczki
    }

    public function testLinkReportsLinksNumbersAndFixesWrongLinks(): void
    {
        $ids = ['UD/3/3074' => 'AbC123', 'UD/3/2887' => 'XyZ789'];
        $this->assertSame('Zgłoszenie [UD/3/3074](/app/AbC123) i **[UD/3/2887](/app/XyZ789)**.',
            \assistant\linkReports('Zgłoszenie UD/3/3074 i **UD/3/2887**.', $ids));
        $this->assertSame('[UD/3/3074](/app/AbC123)', \assistant\linkReports('[UD/3/3074](/app/list)', $ids)); // zły adres → poprawny
        $this->assertSame('[UD/3/3074](/app/AbC123)', \assistant\linkReports('[UD/3/3074](/app/AbC123)', $ids)); // nie linkujemy dwa razy
        $this->assertSame('UD/9/9 nie jest moje', \assistant\linkReports('UD/9/9 nie jest moje', $ids));       // nieznany numer zostaje
        $this->assertSame(['UD/1/2' => 'a'], \assistant\tools\reportIds(['reports' => [['id' => 'a', 'number' => 'UD/1/2']]]));
        $this->assertSame(['UD/1/2' => 'a'], \assistant\tools\reportIds(['id' => 'a', 'number' => 'UD/1/2']));
    }

    public function testReportStatsCountsCurrentEverAndByYear(): void
    {
        $owner = $this->user('stats@example.com');
        $a = $this->report($owner, 'AA1', status: 'confirmed-fined');
        $a->statusHistory = ['2026-03-01T10:00:00' => (object)['old' => 'confirmed', 'new' => 'confirmed-complaint'],
            '2026-04-01T10:00:00' => (object)['old' => 'confirmed-complaint', 'new' => 'confirmed-fined']];
        \app\save($a);
        $this->report($owner, 'BB2', status: 'confirmed-fined');
        $stats = fn(array $args) => \assistant\tools\execute('report_stats', $args, $owner);

        $this->assertSame(2, $stats(['status' => 'confirmed-fined'])['count']);
        $this->assertSame(0, $stats(['status' => 'confirmed-complaint'])['count']);         // obecny status
        $this->assertSame(1, $stats(['status' => 'confirmed-complaint', 'ever' => true])['count']); // kiedykolwiek
        $this->assertSame(1, $stats(['status' => 'confirmed-fined', 'year' => 2026])['count']);
        $this->assertSame(0, $stats(['status' => 'confirmed-fined', 'year' => 2025])['count']);
        $this->assertArrayHasKey('error', $stats(['status' => 'nie-ma']));
        $this->assertSame(0, \assistant\tools\execute('report_stats', ['status' => 'confirmed-fined'], $this->user('other-stats@example.com'))['count']);
    }

    public function testResolutionTimeAveragesDaysToFirstResponse(): void
    {
        $owner = $this->user('restime@example.com');
        $a = $this->report($owner, 'AA1', status: 'confirmed-fined'); // date 2026-05-07
        $a->statusHistory = ['2026-05-09T10:00:00' => (object)['old' => 'confirmed-waiting', 'new' => 'confirmed-instructed'],
            '2026-06-01T10:00:00' => (object)['old' => 'confirmed-instructed', 'new' => 'confirmed-fined']];
        \app\save($a);
        $b = $this->report($owner, 'BB2', status: 'confirmed-ignored');
        $b->statusHistory = ['2026-05-17T10:00:00' => (object)['old' => 'confirmed-waiting', 'new' => 'confirmed-ignored']];
        \app\save($b);
        $this->report($owner, 'CC3', status: 'confirmed-waiting');
        $res = fn(?int $year = null) => \assistant\tools\execute('resolution_time', $year ? ['year' => $year] : [], $owner);

        $r = $res();
        $this->assertSame(2, $r['answered']);
        $this->assertSame(1, $r['withoutResponse']);
        $this->assertSame(6.0, $r['avgDays']); // 2 i 10 dni
        $this->assertSame(2.0, $r['minDays']);
        $this->assertSame(10.0, $r['maxDays']);
        $this->assertSame(0, $res(2025)['answered']);
        $this->assertSame(0, \assistant\tools\execute('resolution_time', [], $this->user('other-restime@example.com'))['answered']);
    }

    public function testSearchLegalFindsArticleByNumber(): void
    {
        $first = fn(array $args) => \assistant\tools\execute('search_legal', $args, $this->user())['results'];
        $r = $first(['query' => 'art 92a']); // samo „art. N” nie ma słów kluczowych, a trafia w artykuł
        $this->assertSame(['prd', 'Art. 92a'], [$r[0]['act'], $r[0]['article']]);
        $r = $first(['query' => 'Art. 60 parkowanie', 'act' => 'prd']);
        $this->assertSame('Art. 60', $r[0]['article']); // nie „Art. 60¹”
        $keys = array_map(fn($x) => $x['act'] . $x['article'], $r);
        $this->assertSame($keys, array_values(array_unique($keys))); // bez duplikatów
    }
}
