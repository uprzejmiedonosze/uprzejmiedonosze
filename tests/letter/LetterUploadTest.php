<?php

namespace UprzejmieDonosze\Tests\Letter;

require_once __DIR__ . '/../../export/inc/LetterProposal.php';
require_once __DIR__ . '/../../export/inc/API.php'; // MAX_IMAGE_UPLOAD_BYTES

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UploadedFileFactory;

use function letter\imageFromRequest;

/** POST /api/rest/letters/analyze przyjmuje jedno zdjęcie w polu `image` (JPEG albo PNG → JPEG), nic więcej. */
class LetterUploadTest extends TestCase
{
    private static function jpeg(): string
    {
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img, null, 80);
        return ob_get_clean();
    }

    private static function png(): string
    {
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($img);
        return ob_get_clean();
    }

    private static function gif(): string
    {
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagegif($img);
        return ob_get_clean();
    }

    private static function upload(string $bytes, int $error = UPLOAD_ERR_OK, ?int $size = null): \Psr\Http\Message\UploadedFileInterface
    {
        return (new UploadedFileFactory())->createUploadedFile(
            (new StreamFactory())->createStream($bytes), $size ?? strlen($bytes), $error, 'pismo.jpg', 'image/jpeg');
    }

    private static function request(array $files): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/rest/letters/analyze')->withUploadedFiles($files);
    }

    private function assertRejected(array $files, int $code): void
    {
        try {
            imageFromRequest(self::request($files));
            $this->fail("oczekiwano wyjątku $code");
        } catch (\Exception $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
        }
    }

    public function testJpegIsPassedThroughUnchanged(): void
    {
        $jpeg = self::jpeg();
        $this->assertSame($jpeg, imageFromRequest(self::request(['image' => self::upload($jpeg)])));
    }

    public function testPngIsConvertedToJpeg(): void
    {
        $out = imageFromRequest(self::request(['image' => self::upload(self::png())]));
        $this->assertSame('image/jpeg', getimagesizefromstring($out)['mime']);
    }

    public function testMissingFileIsRejected(): void
    {
        $this->assertRejected([], 400);
    }

    public function testSeveralFilesAreNotAccepted(): void
    {
        // dawny `images[]` (wielostronicowość) już nie istnieje – ani lista, ani drugie pole nie przechodzą
        $this->assertRejected(['image' => [self::upload(self::jpeg()), self::upload(self::jpeg())]], 400);
        $this->assertRejected(['images' => [self::upload(self::jpeg())]], 400);
    }

    public function testUnsupportedFormatIsRejectedWith415(): void
    {
        $this->assertRejected(['image' => self::upload(self::gif())], 415);
        $this->assertRejected(['image' => self::upload('to nie jest obraz')], 415);
    }

    public function testEmptyAndFailedUploadsAreRejected(): void
    {
        $this->assertRejected(['image' => self::upload('')], 400);
        $this->assertRejected(['image' => self::upload(self::jpeg(), UPLOAD_ERR_PARTIAL)], 400);
    }

    public function testOversizedFileIsRejected(): void
    {
        $this->assertRejected(['image' => self::upload(self::jpeg(), UPLOAD_ERR_OK, \MAX_IMAGE_UPLOAD_BYTES + 1)], 400);
    }
}
