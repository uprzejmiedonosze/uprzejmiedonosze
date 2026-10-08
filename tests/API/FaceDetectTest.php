<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/integrations/FaceDetect.php';

use faces\GoogleVisionClient;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\TestCase;
use vision\VisionClient;
use vision\VisionException;

class FaceDetectTest extends TestCase
{
    protected function tearDown(): void
    {
        VisionClient::set(null);
        GoogleVisionClient::set(null);
        parent::tearDown();
    }

    // Szachownica 8x8 pól — każdy region ma dużo krawędzi, więc blur na pewno go zmienia.
    private static function checkerboard(int $w = 400, int $h = 300): string {
        $img = imagecreatetruecolor($w, $h);
        $black = imagecolorallocate($img, 0, 0, 0);
        $white = imagecolorallocate($img, 255, 255, 255);
        $cell = 10;
        for ($y = 0; $y < $h; $y += $cell)
            for ($x = 0; $x < $w; $x += $cell)
                imagefilledrectangle($img, $x, $y, $x + $cell - 1, $y + $cell - 1, (($x + $y) / $cell) % 2 ? $black : $white);
        ob_start();
        imagejpeg($img, null, 100);
        $bytes = ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    private static function nano(string $content): ClientFake {
        return new ClientFake([
            CreateResponse::fake([
                'choices' => [['message' => ['content' => $content]]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 10, 'total_tokens' => 130],
            ]),
        ]);
    }

    private static function gvisionFace(int $x, int $y, int $w, int $h): array {
        return ['boundingPoly' => ['vertices' => [
            ['x' => $x, 'y' => $y], ['x' => $x + $w, 'y' => $y],
            ['x' => $x + $w, 'y' => $y + $h], ['x' => $x, 'y' => $y + $h],
        ]]];
    }

    public function testNoFacesSkipsGoogle(): void {
        $fake = self::nano('{"faces": 0}');
        VisionClient::set($fake);
        GoogleVisionClient::set(fn () => $this->fail('Google Vision nie powinien być wołany'));

        $faces = \faces\detectBytes(self::checkerboard(), 'test-app');

        $this->assertSame(0, $faces->count);
        $this->assertFalse(isset($faces->blurred));
        $fake->chat()->assertSent(function (string $method, array $params): bool {
            return $params['model'] === OPENAI_FACES_MODEL
                && $params['reasoning_effort'] === 'minimal'
                && $params['messages'][0]['content'][1]['image_url']['detail'] === 'low';
        });
    }

    public function testFacesLocatedAreNormalizedAndBlurred(): void {
        VisionClient::set(self::nano('{"faces": 2}'));
        GoogleVisionClient::set(fn () => ['responses' => [['faceAnnotations' => [
            self::gvisionFace(40, 30, 40, 60),
            self::gvisionFace(200, 150, 80, 60),
        ]]]]);

        $faces = \faces\detectBytes(self::checkerboard(400, 300), 'test-app');

        $this->assertSame(2, $faces->count);
        $this->assertTrue($faces->blurred);
        $this->assertEqualsWithDelta(['x' => 0.1, 'y' => 0.1, 'w' => 0.1, 'h' => 0.2], (array)$faces->boxes->{0}, 0.0001);
        $this->assertEqualsWithDelta(['x' => 0.5, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2], (array)$faces->boxes->{1}, 0.0001);
        $this->assertTrue(\faces\hasBoxes($faces));
    }

    public function testGoogleFindsNothingKeepsPhotoHidden(): void {
        VisionClient::set(self::nano('{"faces": 1}'));
        GoogleVisionClient::set(fn () => ['responses' => [[]]]);

        $faces = \faces\detectBytes(self::checkerboard(), 'test-app');

        $this->assertSame(1, $faces->count);
        $this->assertFalse($faces->blurred);
        $this->assertFalse(\faces\hasBoxes($faces));
    }

    public function testGoogleErrorKeepsPhotoHidden(): void {
        VisionClient::set(self::nano('{"faces": 1}'));
        GoogleVisionClient::set(fn () => throw new \RuntimeException('Google Vision: HTTP 403'));

        $faces = \faces\detectBytes(self::checkerboard(), 'test-app');

        $this->assertSame(1, $faces->count);
        $this->assertFalse($faces->blurred);
    }

    public function testInvalidModelAnswerThrows(): void {
        VisionClient::set(self::nano('{"faces": -1}'));
        $this->expectException(VisionException::class);
        \faces\detectBytes(self::checkerboard(), 'test-app');
    }

    public function testBlurChangesOnlyTheBoxWithMargin(): void {
        $src = self::checkerboard(400, 300);
        // box 40x30 px w (100,100) -> z marginesem x 92..156, y 94..142
        $out = \faces\blur($src, [['x' => 0.25, 'y' => 1 / 3, 'w' => 0.1, 'h' => 0.1]]);

        $before = imagecreatefromstring($src);
        $after = imagecreatefromstring($out);
        $diff = fn (int $x, int $y) => abs((imagecolorat($before, $x, $y) & 0xFF) - (imagecolorat($after, $x, $y) & 0xFF));

        // środek boxa: szachownica rozmyta do szarości
        $changed = 0;
        for ($x = 105; $x < 135; $x += 3) $changed += $diff($x, 115) > 60 ? 1 : 0;
        $this->assertGreaterThan(5, $changed);

        // daleko od boxa: bez zmian (tolerancja na rekompresję JPEG)
        foreach ([[10, 10], [300, 250], [390, 20], [50, 280]] as [$x, $y]) {
            $this->assertLessThan(40, $diff($x, $y), "piksel ($x,$y) nie powinien się zmienić");
        }
    }

    public function testBlurAcceptsStoredJsonObjectBoxes(): void {
        $stored = new \JSONObject('{"count":1,"blurred":true,"boxes":[{"x":0.1,"y":0.1,"w":0.2,"h":0.2}]}');
        $this->assertTrue(\faces\hasBoxes($stored));
        $out = \faces\blur(self::checkerboard(), $stored->boxes);
        $this->assertNotFalse(imagecreatefromstring($out));
    }
}
