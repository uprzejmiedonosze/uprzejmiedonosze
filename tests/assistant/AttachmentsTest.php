<?php

namespace UprzejmieDonosze\Tests\Assistant;

require_once __DIR__ . '/../../export/inc/assistant/Assistant.php';

use assistant\ChatClient;
use assistant\ChatException;
use cache\Type;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use quota\QuotaExceededException;
use UprzejmieDonosze\Tests\DatabaseTestCase;
use user\User;

/** Załączniki w czacie: tylko patroni, obrazy i PDF zużywają kredyty zdjęć (\quota). */
class AttachmentsTest extends DatabaseTestCase
{
    private const EMAIL = 'chat-attach@example.com';
    private mixed $patronsBefore;

    protected function setUp(): void
    {
        parent::setUp();
        \assistant\corpus\setRoot(__DIR__ . '/../fixtures/assistant');
        $this->patronsBefore = \cache\get(Type::Patronite, '');
        $this->setPatron(true);
    }

    protected function tearDown(): void
    {
        ChatClient::set(null);
        \assistant\corpus\setRoot(null);
        \cache\delete(Type::Patronite, '');
        if ($this->patronsBefore) \cache\set(Type::Patronite, '', $this->patronsBefore, 0, 60);
        parent::tearDown();
    }

    /** Niepusty cache patronów = test offline (jak PhotoQuotaTest). Kwota 5 zł = próg „below-min”, limit PHOTO_QUOTA_FREE. */
    private function setPatron(bool $active): void
    {
        $patrons = ['someone-else@example.com' => ['amount' => 5, 'note' => '', 'active' => true]];
        if ($active) $patrons[self::EMAIL] = ['amount' => 5, 'note' => '', 'active' => true];
        \cache\set(Type::Patronite, '', $patrons, 0, 60);
    }

    private function user(): User
    {
        $_SESSION['user_email'] = self::EMAIL;
        $_SESSION['user_id'] = 'phpunit-user-id';
        $user = new User();
        $user->email = self::EMAIL;
        $user->data->email = self::EMAIL;
        $user->data->name = 'Test User';
        $user->data->address = 'Testowa 1, Szczecin';
        \user\save($user);
        return $user;
    }

    private static function image(string $format = 'jpeg'): string
    {
        $img = imagecreatetruecolor(16, 16);
        imagefill($img, 0, 0, imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255))); // unikalny sha1
        ob_start();
        $format === 'png' ? imagepng($img) : imagejpeg($img);
        return ob_get_clean();
    }

    private static function file(string $name, string $bytes, string $mime): array
    {
        return ['name' => $name, 'data' => "data:$mime;base64," . base64_encode($bytes)];
    }

    private static function pdf(): array
    {
        return self::file('pismo.pdf', "%PDF-1.4\n%test\n", 'application/pdf');
    }

    private static function ok(string $content = 'Widzę plik.'): CreateResponse
    {
        return CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']]]);
    }

    private function used(): int
    {
        return \quota\status($this->user())['used'];
    }

    public function testNonPatronCannotAttachAnything(): void
    {
        $this->setPatron(false);
        ChatClient::set(new ClientFake([self::ok()]));
        try {
            \assistant\reply($this->user(), 'Zobacz', [self::pdf()]);
            $this->fail('oczekiwano ChatException');
        } catch (ChatException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('patronite', $e->getMessage());
        }
        $this->assertSame([], \chat\history(self::EMAIL));
        $this->assertFalse(\assistant\attachments\isAllowed($this->user()));
    }

    public function testNonPatronStillChatsWithoutAttachments(): void
    {
        $this->setPatron(false);
        ChatClient::set(new ClientFake([self::ok('Cześć')]));
        $this->assertSame('Cześć', \assistant\reply($this->user(), 'Hej')['content']);
    }

    public function testImageConsumesOneCreditAndReachesModelAsImagePart(): void
    {
        $fake = new ClientFake([self::ok()]);
        ChatClient::set($fake);
        $before = $this->used();

        \assistant\reply($this->user(), 'Co to za znak?', [self::file('znak.jpg', self::image(), 'image/jpeg')]);

        $this->assertSame($before + 1, $this->used());
        $this->assertSame([['name' => 'znak.jpg', 'type' => 'image']], \chat\history(self::EMAIL)[0]['meta']['attachments']);
        $fake->chat()->assertSent(function (string $method, array $params) {
            $parts = end($params['messages'])['content'];
            return $parts[0] === ['type' => 'text', 'text' => 'Co to za znak?']
                && $parts[1]['type'] === 'image_url'
                && str_starts_with($parts[1]['image_url']['url'], 'data:image/jpeg;base64,');
        });
    }

    public function testSameImageIsChargedOnce(): void
    {
        ChatClient::set(new ClientFake([self::ok(), self::ok()]));
        $file = self::file('a.jpg', self::image(), 'image/jpeg');
        $before = $this->used();
        \assistant\reply($this->user(), 'raz', [$file]);
        \assistant\reply($this->user(), 'dwa', [$file]);
        $this->assertSame($before + 1, $this->used());
    }

    public function testPdfConsumesOneCreditAndGoesAsFilePart(): void
    {
        $fake = new ClientFake([self::ok()]);
        ChatClient::set($fake);
        $before = $this->used();
        \assistant\reply($this->user(), 'Co w piśmie?', [self::pdf()]);
        $this->assertSame($before + 1, $this->used());
        $fake->chat()->assertSent(function (string $method, array $params) {
            $part = end($params['messages'])['content'][1];
            return $part['type'] === 'file' && $part['file']['filename'] === 'pismo.pdf'
                && str_starts_with($part['file']['file_data'], 'data:application/pdf;base64,');
        });
    }

    public function testMixedAttachmentsChargeEachOnceAndRefundTogether(): void
    {
        ChatClient::set(new ClientFake([self::ok()]));
        $before = $this->used();
        \assistant\reply($this->user(), 'Razem', [self::pdf(), self::file('a.jpg', self::image(), 'image/jpeg')]);
        $this->assertSame($before + 2, $this->used());

        ChatClient::set(new ClientFake([])); // awaria modelu
        $reply = \assistant\reply($this->user(), 'Awaria', [self::file('p.pdf', "%PDF-1.4\nnowy\n", 'application/pdf'), self::file('b.jpg', self::image(), 'image/jpeg')]);
        $this->assertTrue($reply['meta']['error']);
        $this->assertSame($before + 2, $this->used()); // oba zwrócone
    }

    public function testSamePdfIsChargedOnce(): void
    {
        ChatClient::set(new ClientFake([self::ok(), self::ok()]));
        $before = $this->used();
        \assistant\reply($this->user(), 'raz', [self::pdf()]);
        \assistant\reply($this->user(), 'dwa', [self::pdf()]);
        $this->assertSame($before + 1, $this->used());
    }

    public function testPngIsConvertedToJpeg(): void
    {
        $items = \assistant\attachments\decode([self::file('a.png', self::image('png'), 'image/png')]);
        $this->assertSame('image/jpeg', $items[0]['mime']);
        $this->assertSame("\xFF\xD8", substr($items[0]['bytes'], 0, 2));
    }

    public function testQuotaExhaustedThrows402AndSavesNothing(): void
    {
        ChatClient::set(new ClientFake([self::ok()]));
        $stmt = \store\prepare('INSERT INTO photo_usage (user_email, sha1, created_at) VALUES (:e, :h, :t)');
        for ($i = 0; $i < PHOTO_QUOTA_FREE; $i++) $stmt->execute([':e' => self::EMAIL, ':h' => sha1("fill$i"), ':t' => time()]);

        try {
            \assistant\reply($this->user(), 'Jeszcze jedno', [self::file('b.jpg', self::image(), 'image/jpeg')]);
            $this->fail('oczekiwano QuotaExceededException');
        } catch (QuotaExceededException $e) {
            $this->assertSame(402, $e->getCode());
        }
        $this->assertSame(PHOTO_QUOTA_FREE, $this->used()); // rezerwacja cofnięta
        $this->assertSame([], \chat\history(self::EMAIL));
    }

    public function testModelFailureRefundsCredits(): void
    {
        ChatClient::set(new ClientFake([])); // awaria modelu
        $before = $this->used();
        $reply = \assistant\reply($this->user(), 'Zobacz', [self::file('c.jpg', self::image(), 'image/jpeg')]);
        $this->assertTrue($reply['meta']['error']);
        $this->assertSame($before, $this->used());
    }

    public function testAttachmentWithoutTextIsAllowed(): void
    {
        ChatClient::set(new ClientFake([self::ok()]));
        \assistant\reply($this->user(), '', [self::pdf()]);
        $this->assertSame('Zobacz załączone pliki.', \chat\history(self::EMAIL)[0]['content']);
    }

    public function testOlderTurnsKeepOnlyFileNames(): void
    {
        ChatClient::set(new ClientFake([self::ok(), self::ok()]));
        \assistant\reply($this->user(), 'Pierwsze', [self::pdf()]);
        \assistant\reply($this->user(), 'Drugie');
        $messages = \assistant\buildMessages($this->user());
        $this->assertStringContainsString('[Załączone pliki: pismo.pdf', $messages[1]['content']);
        $this->assertIsString($messages[1]['content']); // bez bajtów
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFiles')]
    public function testRejectsBadFiles(array $files, int $code): void
    {
        try {
            \assistant\attachments\decode($files);
            $this->fail('oczekiwano ChatException');
        } catch (ChatException $e) {
            $this->assertSame($code, $e->getCode());
        }
    }

    public static function badFiles(): array
    {
        return [
            'zły typ' => [[self::file('a.gif', 'GIF89a', 'image/gif')], 415],
            'nie-obraz pod deklaracją jpeg' => [[self::file('a.jpg', 'to nie jest obraz', 'image/jpeg')], 415],
            'zły base64' => [[['name' => 'a.pdf', 'data' => 'data:application/pdf;base64,@@@']], 400],
            'za dużo plików' => [array_fill(0, CHAT_MAX_ATTACHMENTS + 1, self::pdf()), 400],
            'za duży plik' => [[self::file('a.pdf', '%PDF-' . str_repeat('x', CHAT_MAX_ATTACHMENT_BYTES), 'application/pdf')], 400],
            'nie lista' => [['a' => self::pdf()], 400],
        ];
    }

    public function testFileNameIsSanitized(): void
    {
        $items = \assistant\attachments\decode([self::file("../../etc/pa\nsswd", '%PDF-1', 'application/pdf')]);
        $this->assertSame('passwd', $items[0]['name']);
    }
}
