<?php

namespace UprzejmieDonosze\Tests\Assistant;

require_once __DIR__ . '/../../export/inc/handlers/AbstractHandler.php';
require_once __DIR__ . '/../../export/inc/handlers/ChatHandler.php';

use assistant\ChatClient;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use UprzejmieDonosze\Tests\DatabaseTestCase;
use user\User;

class ChatHandlerTest extends DatabaseTestCase
{
    private const EMAIL = 'chat-handler@example.com';

    private mixed $patronsBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        \assistant\corpus\setRoot(__DIR__ . '/../fixtures/assistant');
        $this->patronsBefore = \cache\get(\cache\Type::Patronite, '');
        $this->setPatrons([self::EMAIL => true]); // niepusty cache patronów = test offline
    }

    protected function tearDown(): void
    {
        ChatClient::set(null);
        \assistant\corpus\setRoot(null);
        \cache\delete(\cache\Type::Patronite, '');
        if ($this->patronsBefore) \cache\set(\cache\Type::Patronite, '', $this->patronsBefore, 0, 60);
        parent::tearDown();
    }

    /** @param array<string, bool> $patrons email => aktywny */
    private function setPatrons(array $patrons): void
    {
        $patrons += ['someone-else@example.com' => true];
        \cache\set(\cache\Type::Patronite, '', array_map(
            fn(bool $active) => ['amount' => 5, 'note' => '', 'active' => $active], $patrons), 0, 60);
    }

    private function call(string $method, array $body = [], array $query = [], string $email = self::EMAIL): array
    {
        $user = new User();
        $user->data->email = $email;
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/chat')
            ->withAttribute('user', $user)->withParsedBody($body)->withQueryParams($query);
        $response = (new \ChatHandler())->$method($request, (new ResponseFactory())->createResponse(), []);
        return json_decode((string)$response->getBody(), true);
    }

    public function testSendThenHistoryThenUnreadThenClear(): void
    {
        ChatClient::set(new ClientFake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Odpowiedź'], 'finish_reason' => 'stop']]])]));
        $sent = $this->call('send', ['message' => 'Cześć']);
        $this->assertSame('Odpowiedź', $sent['message']['content']);

        \chat\notify(self::EMAIL, 'Nowy status zgłoszenia');
        $this->assertSame(1, $this->call('unread')['unread']);

        $history = $this->call('history');
        $this->assertSame(['user', 'assistant', 'notification'], array_column($history['messages'], 'role'));
        $this->assertSame(0, $this->call('unread')['unread']); // historia oznacza powiadomienia jako przeczytane

        $this->assertSame(3, $this->call('clear')['archived']);
        $this->assertSame([], $this->call('history')['messages']); // nowa rozmowa jest pusta…
        $this->assertSame(3, (int)\store\query("SELECT COUNT(*) FROM chat_messages WHERE user_email = '" . self::EMAIL . "' AND archived_at IS NOT NULL")->fetchColumn()); // …a stara zostaje w bazie
    }

    public function testUnreadReportsWhetherUserCanAttach(): void
    {
        $this->assertTrue($this->call('unread')['canAttach']);
        $this->setPatrons([self::EMAIL => false]); // były patron: czat tak, załączniki nie
        $this->assertFalse($this->call('unread')['canAttach']);
    }

    public function testFormerPatronCanChat(): void
    {
        $this->setPatrons([self::EMAIL => false]);
        ChatClient::set(new ClientFake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Hej'], 'finish_reason' => 'stop']]])]));
        $this->assertSame('Hej', $this->call('send', ['message' => 'Cześć'])['message']['content']);
        $this->assertSame(0, $this->call('unread')['unread']);
    }

    public function testNonPatronGets403OnEveryEndpoint(): void
    {
        $outsider = 'not-a-patron@example.com';
        $this->assertFalse(\assistant\isAvailable((function () use ($outsider) { $u = new User(); $u->data->email = $outsider; return $u; })()));
        foreach (['history' => [], 'unread' => [], 'send' => ['message' => 'Cześć'], 'clear' => []] as $method => $body) {
            try {
                $this->call($method, $body, [], $outsider);
                $this->fail("$method: oczekiwano 403");
            } catch (\Exception $e) {
                $this->assertSame(403, $e->getCode(), $method);
                $this->assertStringContainsString('patronite', $e->getMessage());
            }
        }
        $this->assertSame([], \chat\history($outsider));
    }

    public function testSendWithoutMessageIsMissingParam(): void
    {
        $this->expectException(\MissingParamException::class);
        $this->call('send', []);
    }

    public function testTooLongMessageSurfacesAs400(): void
    {
        try {
            $this->call('send', ['message' => str_repeat('a', CHAT_MAX_MESSAGE_CHARS + 1)]);
            $this->fail('oczekiwano wyjątku');
        } catch (\Exception $e) {
            $this->assertSame(400, $e->getCode());
        }
    }
}
