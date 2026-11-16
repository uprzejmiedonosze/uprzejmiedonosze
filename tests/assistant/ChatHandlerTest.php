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

    private function call(string $method, array $body = [], array $query = []): array
    {
        $user = new User();
        $user->data->email = self::EMAIL;
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

        $this->assertSame(3, $this->call('clear')['deleted']);
        $this->assertSame([], $this->call('history')['messages']);
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
