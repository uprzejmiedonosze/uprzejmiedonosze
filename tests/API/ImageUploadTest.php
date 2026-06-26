<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/handlers/SessionApiHandler.php';

use app\Application;
use Exception;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UploadedFileFactory;
use Slim\Psr7\Response;
use user\User;

class ImageUploadTest extends TestCase
{
    /** @var list<string> */
    private array $pathsToRemove = [];

    protected function tearDown(): void
    {
        foreach ($this->pathsToRemove as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->pathsToRemove = [];
    }

    private function createSavedUser(string $email = 'image-upload-test@example.com'): User
    {
        $_SESSION['user_email'] = $email;
        $_SESSION['user_id'] = 'phpunit-user-id';
        $user = new User();
        $user->email = $email;
        \user\save($user);
        return $user;
    }

    private function makeJpegBytes(int $width = 40, int $height = 30): string
    {
        $img = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($img, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    private function trackOutputFiles(string $baseFileName, string $type): void
    {
        $this->pathsToRemove[] = ROOT . "$baseFileName,$type.jpg";
        $this->pathsToRemove[] = ROOT . "$baseFileName,$type,t.jpg";
    }

    private function createUploadedFile(string $bytes, string $filename = 'image.jpg'): \Psr\Http\Message\UploadedFileInterface
    {
        $stream = (new StreamFactory())->createStream($bytes);
        return (new UploadedFileFactory())->createUploadedFile(
            $stream,
            strlen($bytes),
            UPLOAD_ERR_OK,
            $filename,
            'image/jpeg'
        );
    }

    public function testSaveImgAndThumbWritesJpegAndThumbFromRawBytes(): void
    {
        $user = $this->createSavedUser('img-thumb-test@example.com');
        $app = Application::withUser($user);
        $jpeg = $this->makeJpegBytes();
        $type = 'co';

        $baseFileName = saveImgAndThumb($app, $jpeg, $type);
        $this->trackOutputFiles($baseFileName, $type);

        $fullPath = ROOT . "$baseFileName,$type.jpg";
        $thumbPath = ROOT . "$baseFileName,$type,t.jpg";

        $this->assertFileExists($fullPath);
        $this->assertFileExists($thumbPath);
        $this->assertSame('image/jpeg', mime_content_type($fullPath));
        $this->assertSame('image/jpeg', mime_content_type($thumbPath));
    }

    public function testSaveImgAndThumbRejectsInvalidBytes(): void
    {
        $user = $this->createSavedUser('img-invalid-test@example.com');
        $app = Application::withUser($user);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Przesłany plik nie jest obrazkiem');

        saveImgAndThumb($app, 'not-an-image', 'co');
    }

    public function testUploadImageContextImageFromRawBytes(): void
    {
        $user = $this->createSavedUser();
        $app = Application::withUser($user);
        $app->statusHistory = [];
        $app->comments = [];
        $app->extensions = [];
        \app\save($app);

        $jpeg = $this->makeJpegBytes(120, 90);
        $updated = uploadImage($app->id, 'contextImage', $jpeg, null, null, null);

        $this->trackOutputFiles(
            \storage\cdnPrefix() . '/' . $updated->getUserNumber() . '/' . $updated->id,
            'co'
        );

        $this->assertNotEmpty($updated->contextImage->url);
        $this->assertNotEmpty($updated->contextImage->thumb);
        $this->assertFileExists(ROOT . $updated->contextImage->url);
        $this->assertFileExists(ROOT . $updated->contextImage->thumb);
        $this->assertSame(120, $updated->contextImage->width);
        $this->assertSame(90, $updated->contextImage->height);
    }

    public function testImageHandlerRejectsMissingUpload(): void
    {
        $handler = new \SessionApiHandler();
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/app/x/image')
            ->withParsedBody(['pictureType' => 'contextImage']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Brak pliku obrazka');

        $handler->image($request, new Response(), ['appId' => 'unused']);
    }

    public function testImageHandlerRejectsOversizedUpload(): void
    {
        $handler = new \SessionApiHandler();
        $oversized = str_repeat('a', 2_097_153);
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/app/x/image')
            ->withParsedBody(['pictureType' => 'contextImage'])
            ->withUploadedFiles(['image' => $this->createUploadedFile($oversized)]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Zbyt duże zdjęcie (>2MB)');

        $handler->image($request, new Response(), ['appId' => 'unused']);
    }
}
