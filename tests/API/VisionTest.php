<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/integrations/Vision.php';

use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\TestCase;
use vision\VisionClient;
use vision\VisionException;
use vision\VisionNetworkException;
use vision\VisionRequestException;

class VisionTest extends TestCase
{
    protected function tearDown(): void
    {
        VisionClient::set(null); // nigdy nie zostawiaj override'u dla innego testu
        parent::tearDown();
    }

    private static function jpeg(int $w = 200, int $h = 150): string {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 60, 120, 60));
        ob_start();
        imagejpeg($img, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    private static function jpegDataUri(int $w = 200, int $h = 150): string {
        return 'data:image/jpeg;base64,' . base64_encode(self::jpeg($w, $h));
    }

    private static function photosJson(array $entries): string {
        return json_encode(['photos' => $entries]);
    }

    // --- VisionSchema (pure, no network) ------------------------------------

    public function testNormPlateKey(): void {
        $this->assertSame('ZS228FC', \vision\normPlateKey('ZS 228FC'));
        $this->assertSame('ZS228FC', \vision\normPlateKey('zs-228-fc'));
        $this->assertNull(\vision\normPlateKey('ABC')); // za krótkie, brak cyfry
        $this->assertNull(\vision\normPlateKey('123456')); // brak litery
    }

    public function testValidateVisionPhotoHappyPath(): void {
        $p = [
            'photo_index' => 0, 'role' => 'car', 'quality' => 0.8,
            'car' => ['present' => true, 'bbox' => [100, 100, 900, 900], 'desc' => null],
            'plate' => ['readable' => true, 'text' => 'ZS228FC', 'bbox' => [400, 700, 600, 780]],
            'markers' => ['sidewalk_parking'],
        ];
        $this->assertSame([], \vision\validateVisionPhoto($p, 0));
    }

    public function testValidateVisionPhotoCatchesBadPlateFormat(): void {
        $p = ['photo_index' => 0, 'role' => 'car', 'quality' => 0.5,
              'car' => ['present' => false, 'bbox' => null], 'plate' => ['readable' => true, 'text' => 'AB', 'bbox' => null], 'markers' => []];
        $errs = \vision\validateVisionPhoto($p, 0);
        $this->assertNotEmpty(array_filter($errs, fn ($e) => str_contains($e, 'nieczytelny format tablicy')));
    }

    public function testValidateVisionPhotoCatchesPlateOutsideCar(): void {
        $p = [
            'photo_index' => 0, 'role' => 'car', 'quality' => 0.8,
            'car' => ['present' => true, 'bbox' => [100, 100, 300, 300]],
            'plate' => ['readable' => true, 'text' => 'ZS228FC', 'bbox' => [800, 800, 900, 900]],
            'markers' => [],
        ];
        $this->assertContains('tablica poza autem', \vision\validateVisionPhoto($p, 0));
    }

    public function testValidateVisionPhotoCatchesWrongIndexAndUnknownMarker(): void {
        $p = ['photo_index' => 5, 'role' => 'context', 'quality' => 0.5, 'markers' => ['nope_not_a_marker']];
        $errs = \vision\validateVisionPhoto($p, 0);
        $this->assertContains('zly photo_index', $errs);
        $this->assertContains('nieznany marker', $errs);
    }

    public function testNormalizeVisionPhotoFlipsUnreadableOnBadFormat(): void {
        $p = ['plate' => ['readable' => true, 'text' => 'AB', 'bbox' => null]];
        \vision\normalizeVisionPhoto($p);
        $this->assertFalse($p['plate']['readable']);
        $this->assertNull($p['plate']['text']);
    }

    public function testExtractJsonHandlesProseAndFences(): void {
        $r = \vision\extractJson('sure, here is the json: {"photos":[1]} thanks');
        $this->assertSame(['photos' => [1]], $r['parsed']);
        $this->assertFalse($r['truncated']);

        $r2 = \vision\extractJson("```json\n{\"photos\":[]}\n```");
        $this->assertSame(['photos' => []], $r2['parsed']);
    }

    public function testExtractJsonDetectsTruncation(): void {
        $r = \vision\extractJson('{"photos": [ {"a": 1}');
        $this->assertNull($r['parsed']);
        $this->assertTrue($r['truncated']);
    }

    public function testExtractJsonNoObjectAtAll(): void {
        $r = \vision\extractJson('nothing here');
        $this->assertNull($r['parsed']);
        $this->assertFalse($r['truncated']);
    }

    // --- decodeCandidatePhotos (REST body validation) -----------------------

    public function testDecodeCandidatePhotosRejectsEmpty(): void {
        $this->expectException(VisionRequestException::class);
        \vision\decodeCandidatePhotos([]);
    }

    public function testDecodeCandidatePhotosRejectsTooMany(): void {
        $entries = [];
        for ($i = 0; $i <= VISION_MAX_PHOTOS; $i++) {
            $entries[] = ['photoId' => "p$i", 'photo_index' => $i, 'image' => self::jpegDataUri()];
        }
        $this->expectException(VisionRequestException::class);
        \vision\decodeCandidatePhotos($entries);
    }

    public function testDecodeCandidatePhotosRejectsNonContiguousIndices(): void {
        $entries = [
            ['photoId' => 'p0', 'photo_index' => 0, 'image' => self::jpegDataUri()],
            ['photoId' => 'p2', 'photo_index' => 2, 'image' => self::jpegDataUri()],
        ];
        $this->expectException(VisionRequestException::class);
        \vision\decodeCandidatePhotos($entries);
    }

    public function testDecodeCandidatePhotosRejectsBadMime(): void {
        $entries = [['photoId' => 'p0', 'photo_index' => 0, 'image' => 'data:application/pdf;base64,' . base64_encode('not an image')]];
        try {
            \vision\decodeCandidatePhotos($entries);
            $this->fail('expected VisionRequestException');
        } catch (VisionRequestException $e) {
            $this->assertSame(400, $e->httpStatus); // regex nie dopuszcza pdf, więc to 400 (zły format data URI)
        }
    }

    public function testDecodeCandidatePhotosRejects415OnMimeMismatch(): void {
        // prefix deklaruje jpeg, ale bajty to GIF -> getimagesizefromstring wykryje prawdę
        $img = imagecreatetruecolor(10, 10);
        ob_start(); imagegif($img); $gifBytes = ob_get_clean(); imagedestroy($img);
        $entries = [['photoId' => 'p0', 'photo_index' => 0, 'image' => 'data:image/jpeg;base64,' . base64_encode($gifBytes)]];
        try {
            \vision\decodeCandidatePhotos($entries);
            $this->fail('expected VisionRequestException');
        } catch (VisionRequestException $e) {
            $this->assertSame(415, $e->httpStatus);
        }
    }

    public function testDecodeCandidatePhotosAcceptsPngAndNormalizesToJpeg(): void {
        $img = imagecreatetruecolor(50, 40);
        ob_start(); imagepng($img); $pngBytes = ob_get_clean(); imagedestroy($img);
        $entries = [['photoId' => 'p0', 'photo_index' => 0, 'image' => 'data:image/png;base64,' . base64_encode($pngBytes)]];
        $items = \vision\decodeCandidatePhotos($entries);
        $info = getimagesizefromstring($items[0]['bytes']);
        $this->assertSame('image/jpeg', $info['mime']);
    }

    public function testDecodeCandidatePhotosHappyPathSortsByIndex(): void {
        $entries = [
            ['photoId' => 'p1', 'photo_index' => 1, 'image' => self::jpegDataUri()],
            ['photoId' => 'p0', 'photo_index' => 0, 'image' => self::jpegDataUri()],
        ];
        $items = \vision\decodeCandidatePhotos($entries);
        $this->assertSame(['p0', 'p1'], array_column($items, 'photoId'));
    }

    // --- cropJpeg (GD unit) --------------------------------------------------

    public function testCropJpegProducesExpectedDimensionsAndMime(): void {
        $bytes = self::jpeg(400, 300);
        $out = \vision\cropJpeg($bytes, 100, 100, 200, 150, 1000);
        $this->assertNotNull($out);
        $info = getimagesizefromstring($out);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame(200, $info[0]);
        $this->assertSame(150, $info[1]);
    }

    public function testCropJpegDownscalesToMaxSide(): void {
        $bytes = self::jpeg(2000, 1000);
        $out = \vision\cropJpeg($bytes, 0, 0, 2000, 1000, 500);
        $info = getimagesizefromstring($out);
        $this->assertLessThanOrEqual(500, max($info[0], $info[1]));
    }

    public function testCropJpegRejectsDegenerateBox(): void {
        $this->assertNull(\vision\cropJpeg(self::jpeg(), 0, 0, 2, 2, 100));
    }

    // --- analyzeCandidate pipeline (ClientFake) ------------------------------

    private function fakePhotoJson(int $index, string $role = 'context', bool $plateReadable = false, ?string $plateText = null): array {
        return [
            'photo_index' => $index,
            'role' => $role,
            'quality' => 0.8,
            'car' => ['present' => $role === 'car', 'bbox' => $role === 'car' ? [100, 100, 900, 900] : null, 'desc' => null],
            'plate' => ['readable' => $plateReadable, 'text' => $plateText, 'bbox' => $plateReadable ? [400, 700, 600, 780] : null],
            'markers' => $role === 'context' ? ['sidewalk_parking'] : [],
            'suggested_category' => 26,
            'category_confidence' => 0.7,
        ];
    }

    public function testAnalyzeCandidateHappyPath(): void {
        $fake = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' =>
                self::photosJson([
                    $this->fakePhotoJson(0, 'context'),
                    $this->fakePhotoJson(1, 'car', true, 'ZS 228FC'),
                ]),
            ]]]]),
        ]);
        VisionClient::set($fake);

        $photos = [
            ['photoId' => 'a', 'photo_index' => 0, 'bytes' => self::jpeg()],
            ['photoId' => 'b', 'photo_index' => 1, 'bytes' => self::jpeg()],
        ];
        $result = \vision\analyzeCandidate($photos, 'user@example.com', 'R001');

        $this->assertSame(2, count($result['photos']));
        $this->assertSame('a', $result['photos'][0]['photoId']);
        $this->assertSame('b', $result['photos'][1]['photoId']);
        $this->assertSame('ZS228FC', $result['photos'][1]['plate']['text']); // normalizowane (bez spacji)
        $this->assertGreaterThanOrEqual(1, $result['usage']['calls']);

        $fake->chat()->assertSent(function (string $method, array $params) {
            $hasImageUrl = false;
            foreach ($params['messages'][0]['content'] as $part) {
                if (($part['type'] ?? null) === 'image_url' && str_starts_with($part['image_url']['url'], 'data:image/jpeg;base64,')) {
                    $hasImageUrl = true;
                }
            }
            return $params['model'] === OPENAI_VISION_MODEL
                && $params['response_format']['type'] === 'json_object'
                && $hasImageUrl;
        });
    }

    public function testAnalyzeCandidateRepairsNonJsonResponse(): void {
        $fake = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' => 'sorry, I cannot help with that']]]]),
            CreateResponse::fake(['choices' => [['message' => ['content' =>
                self::photosJson([$this->fakePhotoJson(0, 'context')]),
            ]]]]),
        ]);
        VisionClient::set($fake);

        $photos = [['photoId' => 'a', 'photo_index' => 0, 'bytes' => self::jpeg()]];
        $result = \vision\analyzeCandidate($photos, 'user@example.com', 'R002');

        $this->assertSame(1, count($result['photos']));
        $fake->chat()->assertSent(function (string $method, array $params) {
            $msgs = $params['messages'];
            return count($msgs) === 3 && str_contains($msgs[2]['content'], 'not valid JSON');
        });
    }

    public function testAnalyzeCandidateRepairsValidationErrors(): void {
        $fake = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' =>
                self::photosJson([['photo_index' => 0, 'role' => 'context', 'quality' => 0.5, 'markers' => ['not_a_real_marker']]]),
            ]]]]),
            CreateResponse::fake(['choices' => [['message' => ['content' =>
                self::photosJson([$this->fakePhotoJson(0, 'context')]),
            ]]]]),
        ]);
        VisionClient::set($fake);

        $photos = [['photoId' => 'a', 'photo_index' => 0, 'bytes' => self::jpeg()]];
        $result = \vision\analyzeCandidate($photos, 'user@example.com', 'R003');
        $this->assertSame(1, count($result['photos']));

        $fake->chat()->assertSent(function (string $method, array $params) {
            $msgs = $params['messages'];
            return count($msgs) === 3 && str_contains($msgs[2]['content'], 'nieznany marker');
        });
    }

    public function testAnalyzeCandidateThrowsAfterBothRepairsFail(): void {
        $bad = self::photosJson([['photo_index' => 0, 'role' => 'context', 'quality' => 0.5, 'markers' => ['not_a_real_marker']]]);
        $fake = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' => $bad]]]]),
            CreateResponse::fake(['choices' => [['message' => ['content' => $bad]]]]),
        ]);
        VisionClient::set($fake);

        $this->expectException(VisionException::class);
        \vision\analyzeCandidate([['photoId' => 'a', 'photo_index' => 0, 'bytes' => self::jpeg()]], 'user@example.com');
    }

    public function testAnalyzeCandidateRetryCropFillsUnreadablePlate(): void {
        $fake = new ClientFake([
            // 1) głowna analiza: auto bez czytelnej tablicy
            CreateResponse::fake(['choices' => [['message' => ['content' =>
                self::photosJson([$this->fakePhotoJson(0, 'car', false, null)]),
            ]]]]),
            // 2) retry-crop: odczytuje tablicę
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"readable":true,"text":"ZS 228FC"}']]]]),
            // 3) verifyPlateBox: potwierdza
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"text":"ZS228FC","bbox":[100,100,900,300]}']]]]),
        ]);
        VisionClient::set($fake);

        $photos = [['photoId' => 'a', 'photo_index' => 0, 'bytes' => self::jpeg(400, 300)]];
        $result = \vision\analyzeCandidate($photos, 'user@example.com', 'R004');

        $p = $result['photos'][0];
        $this->assertTrue($p['plate']['from_crop']);
        $this->assertSame('ZS228FC', $p['plate']['text']);
        $this->assertTrue($p['plate_verified']);
    }

    public function testAnalyzeCandidateVerifyPlateBoxMismatch(): void {
        $fake = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' =>
                self::photosJson([$this->fakePhotoJson(0, 'car', true, 'ZS 228FC')]),
            ]]]]),
            // verify crop zwraca inna tablice -> mismatch
            CreateResponse::fake(['choices' => [['message' => ['content' => '{"text":"WZ1234A","bbox":[0,0,100,100]}']]]]),
        ]);
        VisionClient::set($fake);

        $photos = [['photoId' => 'a', 'photo_index' => 0, 'bytes' => self::jpeg(400, 300)]];
        $result = \vision\analyzeCandidate($photos, 'user@example.com', 'R005');

        $p = $result['photos'][0];
        $this->assertSame('unverified-box', $p['plate']['plate_check']);
        $this->assertArrayNotHasKey('plate_verified', $p);
    }

    public function testAnalyzeCandidateSplitsLargeCandidateOnNetworkError(): void {
        $err = new \OpenAI\Exceptions\RateLimitException(new \GuzzleHttp\Psr7\Response(429));
        $good = fn (array $entries) => CreateResponse::fake(['choices' => [['message' => ['content' => self::photosJson($entries)]]]]);

        // 4 zdjęcia > 3 => po 3 nieudanych próbach na całości, dzieli na paczki [0,1,2] + [3]
        $fake = new ClientFake([
            $err, $err, $err, // cała paczka pada 3x (wyczerpuje retry w chat())
            $good([$this->fakePhotoJson(0, 'context'), $this->fakePhotoJson(1, 'context'), $this->fakePhotoJson(2, 'context')]),
            $good([$this->fakePhotoJson(3, 'context')]),
        ]);
        VisionClient::set($fake);

        $photos = [];
        for ($i = 0; $i < 4; $i++) $photos[] = ['photoId' => "p$i", 'photo_index' => $i, 'bytes' => self::jpeg()];

        $result = \vision\analyzeCandidate($photos, 'user@example.com', 'R006');
        $this->assertSame(4, count($result['photos']));
        $this->assertSame([0, 1, 2, 3], array_column($result['photos'], 'photo_index'));
    }

    public function testAnalyzeCandidateDoesNotSplitOnValidationFailure(): void {
        // Blad walidacji (nie sieciowy) na >3 zdjeciach NIE powinien wyzwalac fallbacku na paczki
        // (w JS to rozroznia klasa NetworkError; tu VisionNetworkException).
        $bad = self::photosJson([
            ['photo_index' => 0, 'role' => 'context', 'quality' => 0.5, 'markers' => ['nope']],
        ]);
        // tylko photo_index=0 w odpowiedzi mimo 4 zdjec w zadaniu -> blad "zwrocono indeksy" (nie network)
        $fake = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' => $bad]]]]),
        ]);
        VisionClient::set($fake);

        $photos = [];
        for ($i = 0; $i < 4; $i++) $photos[] = ['photoId' => "p$i", 'photo_index' => $i, 'bytes' => self::jpeg()];

        $this->expectException(VisionException::class);
        $this->expectExceptionMessageMatches('/zwrócono indeksy|walidacja/u');
        \vision\analyzeCandidate($photos, 'user@example.com', 'R007');
    }

    // --- throttle -------------------------------------------------------------

    public function testVisionThrottleFixedWindow(): void {
        // \cache\throttle\attempt seeds the counter at 0 on the first call (returns true without
        // incrementing), then increments on every call after - so max=2 allows 3 successful
        // attempts before the 4th is rejected. Same off-by-one as every other throttle\attempt
        // consumer in this codebase (PasskeyHandler) - not something to "fix" here.
        $bucket = 'candidate-throttle-test-' . uniqid();
        $this->assertTrue(\cache\throttle\attempt(\cache\Type::Vision, $bucket, 2, 60));
        $this->assertTrue(\cache\throttle\attempt(\cache\Type::Vision, $bucket, 2, 60));
        $this->assertTrue(\cache\throttle\attempt(\cache\Type::Vision, $bucket, 2, 60));
        $this->assertFalse(\cache\throttle\attempt(\cache\Type::Vision, $bucket, 2, 60));
    }
}
