<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/integrations/Vision.php';

use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\TestCase;
use vision\PlateRecognizerClient;
use vision\VisionClient;
use vision\VisionException;
use vision\VisionNetworkException;
use vision\VisionRequestException;

class VisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Domyślnie "ALPR nic nie znalazł" - testy niezwiązane z applyAlprPlate() (czyli
        // wszystkie poza tymi z prefiksem testAnalyzeCandidateHybridPr*) nie trafiają w sieć
        // i dostają graceful fallback na odczyt modelu, dokładnie jak produkcyjny kod przewiduje.
        PlateRecognizerClient::set(fn (string $bytes) => ['results' => []]);
    }

    protected function tearDown(): void
    {
        VisionClient::set(null); // nigdy nie zostawiaj override'u dla innego testu
        PlateRecognizerClient::set(null);
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

    public function testPixelBoxToVisionSpaceConvertsValidPixels(): void {
        // 10%..90% z 200x150 -> 100..900 / 100..900 w 0..1000
        $this->assertSame([100, 100, 900, 900], \vision\pixelBoxToVisionSpace([20, 15, 180, 135], 200, 150));
        $this->assertNull(\vision\pixelBoxToVisionSpace(null, 200, 150));
    }

    public function testPixelBoxToVisionSpaceLeavesMalformedForValidator(): void {
        // Nie-numeryczne elementy nie mogą rzucać TypeError (500 z pominięciem rundy
        // naprawy) ani cicho znikać w null (null bbox jest dozwolony) — walidacja ma je
        // wyłapać jako "zly bbox" i dać modelowi szansę naprawy.
        $bad = ['left', 'top', 100, 100];
        $this->assertSame($bad, \vision\pixelBoxToVisionSpace($bad, 200, 150));
        $three = [10, 20, 30];
        $this->assertSame($three, \vision\pixelBoxToVisionSpace($three, 200, 150));

        $photos = [['photo_index' => 0, 'car' => ['bbox' => $bad], 'plate' => ['bbox' => null]]];
        \vision\applyPixelBoxes($photos, [0 => ['w' => 200, 'h' => 150]]); // nie rzuca
        $this->assertSame($bad, $photos[0]['car']['bbox']);
        $errs = \vision\validateVisionPhoto(array_merge(
            ['photo_index' => 0, 'role' => 'car', 'quality' => 0.8, 'markers' => []],
            ['car' => ['present' => true, 'bbox' => $photos[0]['car']['bbox']], 'plate' => ['readable' => false, 'text' => null, 'bbox' => null]],
        ), 0);
        $this->assertContains('zly bbox car', $errs);
    }

    // --- analyzeCandidate pipeline (ClientFake) ------------------------------

    // Bbox tu jest w PIKSELACH zdjęcia $w x $h (kontrakt promptu od schema v6 — patrz
    // visionPrompt()/pixelBoxToVisionSpace()), domyślnie zgodnych z self::jpeg()'s 200x150.
    // Wartości dobrane tak, by po konwersji z powrotem do 0..1000 dać dokładnie [100,100,900,900]
    // (auto) i [400,700,600,780] (tablica) - te same liczby, którymi posługiwały się testy przed
    // schema v6, więc assercje na tekst/kształt odpowiedzi nie muszą się zmieniać.
    private function fakePhotoJson(int $index, string $role = 'context', bool $plateReadable = false, ?string $plateText = null, int $w = 200, int $h = 150): array {
        return [
            'photo_index' => $index,
            'role' => $role,
            'quality' => 0.8,
            'car' => ['present' => $role === 'car', 'bbox' => $role === 'car' ? [(int)round(0.1 * $w), (int)round(0.1 * $h), (int)round(0.9 * $w), (int)round(0.9 * $h)] : null, 'desc' => null],
            'plate' => ['readable' => $plateReadable, 'text' => $plateText, 'bbox' => $plateReadable ? [(int)round(0.4 * $w), (int)round(0.7 * $h), (int)round(0.6 * $w), (int)round(0.78 * $h)] : null],
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

    public function testAnalyzeCandidateHybridPrFillsPlateFromAlprForCarRole(): void {
        $fake = new ClientFake([
            // model: auto bez czytelnej tablicy
            CreateResponse::fake(['choices' => [['message' => ['content' =>
                self::photosJson([$this->fakePhotoJson(0, 'car', false, null, 400, 300)]),
            ]]]]),
        ]);
        VisionClient::set($fake);
        PlateRecognizerClient::set(fn (string $bytes) => ['results' => [[
            'plate' => 'zs228fc', 'score' => 0.9,
            'box' => ['xmin' => 40, 'ymin' => 30, 'xmax' => 360, 'ymax' => 270], // 10%..90% z 400x300
            'vehicle' => ['box' => ['xmin' => 0, 'ymin' => 0, 'xmax' => 400, 'ymax' => 300]],
        ]]]);

        $photos = [['photoId' => 'a', 'photo_index' => 0, 'bytes' => self::jpeg(400, 300)]];
        $result = \vision\analyzeCandidate($photos, 'user@example.com', 'R004');

        $p = $result['photos'][0];
        $this->assertTrue($p['plate']['readable']);
        $this->assertSame('ZS228FC', $p['plate']['text']);
        $this->assertSame([100, 100, 900, 900], $p['plate']['bbox']); // 0..1000, konwersja z pikseli
        $this->assertTrue($p['plate_verified']);
        $this->assertSame([0, 0, 1000, 1000], $p['car']['bbox']); // vehicle.box na cały kadr
        $this->assertSame('ZS228FC', $p['plate_debug']['alpr']['text']);
        $this->assertNull($p['plate_debug']['model']['text']); // model sam nie odczytał tablicy
    }

    public function testAnalyzeCandidateHybridPrIgnoresLowScoreAlpr(): void {
        $fake = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' =>
                self::photosJson([$this->fakePhotoJson(0, 'car', true, 'ZS 228FC')]),
            ]]]]),
        ]);
        VisionClient::set($fake);
        PlateRecognizerClient::set(fn (string $bytes) => ['results' => [[
            'plate' => 'WRONG1', 'score' => 0.1, // poniżej ALPR_MIN_SCORE
            'box' => ['xmin' => 80, 'ymin' => 105, 'xmax' => 120, 'ymax' => 117],
            'vehicle' => ['box' => ['xmin' => 0, 'ymin' => 0, 'xmax' => 200, 'ymax' => 150]],
        ]]]);

        $photos = [['photoId' => 'a', 'photo_index' => 0, 'bytes' => self::jpeg()]];
        $result = \vision\analyzeCandidate($photos, 'user@example.com', 'R010');

        $p = $result['photos'][0];
        $this->assertSame('ZS228FC', $p['plate']['text']); // zostaje odczyt modelu
        $this->assertArrayNotHasKey('plate_verified', $p);
        $this->assertNotEmpty($result['warnings']); // niski score odnotowany
    }

    public function testAnalyzeCandidateHybridPrFlagsAlprPlateOutsideCar(): void {
        $fake = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' =>
                self::photosJson([$this->fakePhotoJson(0, 'car', false, null, 400, 300)]),
            ]]]]),
        ]);
        VisionClient::set($fake);
        PlateRecognizerClient::set(fn (string $bytes) => ['results' => [[
            'plate' => 'zs228fc', 'score' => 0.9,
            // tablica poza boxem auta z ALPR, ale w obrębie auta modelu (200,150,300,200
            // na 400x300) — po fladze wraca auto modelu i niezmiennik znowu zachodzi
            'box' => ['xmin' => 200, 'ymin' => 150, 'xmax' => 300, 'ymax' => 200],
            'vehicle' => ['box' => ['xmin' => 0, 'ymin' => 0, 'xmax' => 100, 'ymax' => 75]],
        ]]]);

        $photos = [['photoId' => 'a', 'photo_index' => 0, 'bytes' => self::jpeg(400, 300)]];
        $result = \vision\analyzeCandidate($photos, 'user@example.com', 'R011');

        $p = $result['photos'][0];
        $this->assertSame('ZS228FC', $p['plate']['text']); // tekst ALPR zostaje
        $this->assertSame('unverified-box', $p['plate_check']); // ale rozjazd jest oflagowany
        $this->assertSame([], \vision\validateVisionPhoto($p, 0)); // po przywróceniu auta modelu — czysto
        $this->assertNotEmpty($result['warnings']);
    }

    public function testAnalyzeCandidateHybridPrDoesNotOverrideThirdRole(): void {
        $fake = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' =>
                self::photosJson([$this->fakePhotoJson(0, 'third', true, 'AAA1111')]),
            ]]]]),
        ]);
        VisionClient::set($fake);
        $calls = 0;
        PlateRecognizerClient::set(function (string $bytes) use (&$calls) {
            $calls++;
            return ['results' => []];
        });

        $photos = [['photoId' => 'a', 'photo_index' => 0, 'bytes' => self::jpeg()]];
        $result = \vision\analyzeCandidate($photos, 'user@example.com', 'R008');

        $this->assertSame(0, $calls); // 'third' nie wywołuje ALPR w ogóle
        $p = $result['photos'][0];
        $this->assertSame('AAA1111', $p['plate']['text']); // zostaje odczyt modelu
        $this->assertArrayNotHasKey('plate_debug', $p);
    }

    public function testAnalyzeCandidateHybridPrFallsBackToModelWhenAlprFindsNothing(): void {
        $fake = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' =>
                self::photosJson([$this->fakePhotoJson(0, 'car', true, 'ZS 228FC')]),
            ]]]]),
        ]);
        VisionClient::set($fake);
        PlateRecognizerClient::set(fn (string $bytes) => ['results' => []]); // ALPR nic nie znalazł

        $photos = [['photoId' => 'a', 'photo_index' => 0, 'bytes' => self::jpeg()]];
        $result = \vision\analyzeCandidate($photos, 'user@example.com', 'R009');

        $p = $result['photos'][0];
        $this->assertSame('ZS228FC', $p['plate']['text']); // zostaje odczyt modelu (znormalizowany)
        $this->assertArrayNotHasKey('plate_verified', $p);
        $this->assertNull($p['plate_debug']['alpr']);
        $this->assertSame('ZS228FC', $p['plate_debug']['model']['text']);
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
