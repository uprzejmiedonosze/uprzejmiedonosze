<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/handlers/SessionApiHandler.php';
require_once __DIR__ . '/../../export/inc/integrations/Vision.php';

use app\Application;
use UprzejmieDonosze\Tests\DatabaseTestCase;
use user\User;

/**
 * Etap `wip` (src/inc/WipPhotos.php): zdjęcia lądują poza zgłoszeniem, są analizowane po photoId (ALPR raz na zdjęcie),
 * a dopiero przydział do slotu (assignPhoto) robi z nich zdjęcia zgłoszenia.
 */
class WipPhotosTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $paths = [];
    private int $alprCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alprCalls = 0;
        \vision\PlateRecognizerClient::set(function (string $bytes): array {
            $this->alprCalls++;
            [$w, $h] = getimagesizefromstring($bytes);
            return ['results' => [[
                'plate' => 'zs12345', 'score' => 0.99,
                'box' => ['xmin' => (int)($w * 0.4), 'ymin' => (int)($h * 0.7), 'xmax' => (int)($w * 0.6), 'ymax' => (int)($h * 0.8)],
                'vehicle' => ['box' => ['xmin' => (int)($w * 0.1), 'ymin' => (int)($h * 0.2), 'xmax' => (int)($w * 0.9), 'ymax' => (int)($h * 0.9)]],
            ]]];
        });
    }

    protected function tearDown(): void
    {
        \vision\PlateRecognizerClient::set(null);
        foreach ($this->paths as $p) {
            if (is_file($p)) unlink($p);
        }
        parent::tearDown();
    }

    private function user(string $email): User
    {
        $_SESSION['user_email'] = $email;
        $_SESSION['user_id'] = 'phpunit-user-id';
        $user = new User();
        $user->email = $email;
        $user->data->name = 'Wip Test';
        $user->data->address = 'Testowa 1, Szczecin';
        \user\save($user);
        return $user;
    }

    private function draft(User $user): Application
    {
        $app = Application::withUser($user);
        $app->statusHistory = [];
        $app->comments = [];
        $app->extensions = [];
        \app\save($app);
        return $app;
    }

    private function jpeg(int $w = 2000, int $h = 1000): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($img, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    private function track(string $photoId, User $user): void
    {
        $this->paths[] = \wip\photoPath($user, $photoId);
        $this->paths[] = \wip\sidecarPath($user, $photoId);
    }

    public function testStageWritesPhotoAndSidecarOutsideTheReport(): void
    {
        $user = $this->user('wip-stage@example.com');
        $s = \wip\stage($user, $this->jpeg(), ['dateTime' => '2026-09-10T19:43:00', 'lat' => '53.4', 'lng' => '14.5']);
        $this->track($s['photoId'], $user);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $s['photoId']);
        $this->assertSame([2000, 1000], [$s['width'], $s['height']]);
        $this->assertStringContainsString('/wip/', \wip\photoPath($user, $s['photoId']));
        $this->assertFileExists(\wip\photoPath($user, $s['photoId']));

        $loaded = \wip\load($user, $s['photoId']);
        $this->assertSame(53.4, $loaded['meta']['lat']);
        $this->assertSame('2026-09-10T19:43:00', $loaded['meta']['dateTime']);
        $this->assertNull($loaded['meta']['alpr']);
    }

    public function testLoadRejectsBadAndForeignIds(): void
    {
        $owner = $this->user('wip-owner@example.com');
        $other = $this->user('wip-other@example.com');
        $s = \wip\stage($owner, $this->jpeg(200, 100));
        $this->track($s['photoId'], $owner);

        $this->assertNull(\wip\load($other, $s['photoId']), 'cudze zdjęcie');
        $this->assertNull(\wip\load($owner, '../../etc/passwd'));
        $this->assertNull(\wip\load($owner, str_repeat('a', 32)), 'nieistniejące');
        $this->assertFalse(\wip\delete($owner, '../x'));
    }

    public function testAlprIsCalledOncePerPhoto(): void
    {
        $user = $this->user('wip-alpr@example.com');
        $s = \wip\stage($user, $this->jpeg());
        $this->track($s['photoId'], $user);

        \wip\alpr($user, $s['photoId']);
        \wip\alpr($user, $s['photoId']);
        $this->assertSame(1, $this->alprCalls);
    }

    public function testAssignUsesStoredAlprAndSurvivesRoleSwap(): void
    {
        $user = $this->user('wip-assign@example.com');
        $app = $this->draft($user);

        $a = \wip\stage($user, $this->jpeg(2000, 1000), ['dateTime' => '2026-09-10T19:43:00', 'lat' => 53.43, 'lng' => 14.55]);
        $b = \wip\stage($user, $this->jpeg(1200, 900));
        $this->track($a['photoId'], $user);
        $this->track($b['photoId'], $user);

        // analiza (jak vision/candidate): ALPR policzony raz dla każdego zdjęcia
        \wip\alpr($user, $a['photoId']);
        \wip\alpr($user, $b['photoId']);
        $this->assertSame(2, $this->alprCalls);

        $out = assignPhoto($app->id, 'carImage', $a['photoId'], $user);
        $out = assignPhoto($app->id, 'contextImage', $b['photoId'], $user);
        // zamiana ról: te same zdjęcia, inne sloty – bez ponownego ALPR
        $out = assignPhoto($app->id, 'carImage', $b['photoId'], $user);
        $out = assignPhoto($app->id, 'contextImage', $a['photoId'], $user);
        $this->assertSame(2, $this->alprCalls, 'zamiana ról nie woła ALPR');

        foreach (['carImage', 'contextImage'] as $slot) {
            $this->paths[] = ROOT . $out->$slot->url;
            $this->paths[] = ROOT . $out->$slot->thumb;
        }
        if (isset($out->carInfo->plateImage)) $this->paths[] = ROOT . $out->carInfo->plateImage;

        $this->assertSame('ZS12345', $out->carInfo->plateId);
        // zdjęcie auta po zamianie (b: 1200x900 → plik ≤1600) – box przeliczony na wymiary zapisanego pliku
        $this->assertSame(1200, $out->carImage->width);
        $this->assertEqualsWithDelta(1200 * 0.1, $out->carInfo->vehicleBox->x, 2);
        // szkic zna id zdjęć z wip (sprzątane przy finishApplication)
        $this->assertSame($a['photoId'], $out->wipPhotos->contextImage);
        $this->assertSame($b['photoId'], $out->wipPhotos->carImage);
    }

    public function testAssignUnknownPhotoIs404(): void
    {
        $user = $this->user('wip-404@example.com');
        $app = $this->draft($user);
        $this->expectExceptionCode(404);
        assignPhoto($app->id, 'carImage', str_repeat('b', 32), $user);
    }

    public function testCandidateDecodeReadsWipAndResolvesAlprLazily(): void
    {
        $user = $this->user('wip-vision@example.com');
        $s = \wip\stage($user, $this->jpeg(800, 600));
        $this->track($s['photoId'], $user);

        $items = \vision\decodeCandidatePhotos([['photoId' => $s['photoId'], 'photo_index' => 0]], $user);
        $this->assertSame(\wip\load($user, $s['photoId'])['bytes'], $items[0]['bytes']);
        $this->assertSame(0, $this->alprCalls, 'dekodowanie nie woła ALPR');
        $items[0]['alpr']();
        $items[0]['alpr']();
        $this->assertSame(1, $this->alprCalls);

        $this->expectException(\vision\VisionRequestException::class);
        \vision\decodeCandidatePhotos([['photoId' => str_repeat('c', 32), 'photo_index' => 0]], $user);
    }

    public function testDetectionsListAllReadingsAndMarkTheWinner(): void
    {
        $user = $this->user('wip-detections@example.com');
        $s = \wip\stage($user, $this->jpeg(1000, 500));
        $this->track($s['photoId'], $user);
        // auto z tła: mniejsze, ale pewniejsze (0.99); auto z przodu: większe, trochę mniej pewne (0.95) → remis w paśmie 0.1 → wygrywa większe
        \vision\PlateRecognizerClient::set(function (string $bytes): array {
            $this->alprCalls++;
            return ['results' => [
                ['plate' => 'bg11111', 'score' => 0.99, 'box' => ['xmin' => 10, 'ymin' => 10, 'xmax' => 60, 'ymax' => 30],
                 'vehicle' => ['box' => ['xmin' => 0, 'ymin' => 0, 'xmax' => 200, 'ymax' => 100]]],
                ['plate' => 'zs22222', 'score' => 0.95, 'box' => ['xmin' => 400, 'ymin' => 300, 'xmax' => 500, 'ymax' => 340],
                 'vehicle' => ['box' => ['xmin' => 200, 'ymin' => 100, 'xmax' => 900, 'ymax' => 450]]],
            ]];
        });

        $d = \wip\detections($user, $s['photoId']);
        $this->assertSame([1000, 500], [$d['width'], $d['height']]);
        $this->assertCount(2, $d['detections']);
        $this->assertSame(['BG11111', 'ZS22222'], array_column($d['detections'], 'text'));
        $this->assertSame([false, true], array_column($d['detections'], 'winner'));
        $this->assertEqualsWithDelta(0.098, $d['detections'][1]['vehicle_area'] ?? 0, 0.5, 'pole pojazdu jako ułamek kadru');
        \wip\detections($user, $s['photoId']);
        $this->assertSame(1, $this->alprCalls, 'ALPR raz na zdjęcie');
        $this->assertNull(\wip\detections($user, str_repeat('d', 32)));
    }

    public function testVehicleCropBoxAddsMarginAndClampsToTheFrame(): void
    {
        $this->assertSame([60, 80, 280, 140], \alpr\vehicleCropBox(['xmin' => 100, 'ymin' => 100, 'xmax' => 300, 'ymax' => 200], 1000, 1000));
        // przy krawędzi: margines ucięty do kadru
        $this->assertSame([0, 0, 500, 300], \alpr\vehicleCropBox(['xmin' => 10, 'ymin' => 10, 'xmax' => 480, 'ymax' => 290], 500, 300));
    }

    public function testTranslateMovesBoxesIntoTheCropAndKeepsOtherFields(): void
    {
        $out = \alpr\translatePlateRecognizerResult(['results' => [[
            'plate' => 'ab1', 'box' => ['xmin' => 100, 'ymin' => 80, 'xmax' => 140, 'ymax' => 90],
            'vehicle' => ['box' => ['xmin' => 50, 'ymin' => 20, 'xmax' => 300, 'ymax' => 200]],
        ]]], -40, -10);
        $this->assertSame(['xmin' => 60, 'ymin' => 70, 'xmax' => 100, 'ymax' => 80], $out['results'][0]['box']);
        $this->assertSame(['xmin' => 10, 'ymin' => 10, 'xmax' => 260, 'ymax' => 190], $out['results'][0]['vehicle']['box']);
        $this->assertSame('ab1', $out['results'][0]['plate']);
    }

    public function testAssignWithVehicleCropSavesTheCarAsACutOutAndCallsAlprOnce(): void
    {
        $user = $this->user('wip-crop@example.com');
        $app = $this->draft($user);
        $s = \wip\stage($user, $this->jpeg(2000, 1000), ['dateTime' => '2026-09-10T19:43:00', 'lat' => 53.43, 'lng' => 14.55]);
        $this->track($s['photoId'], $user);

        \wip\detections($user, $s['photoId']); // analiza: 1 wywołanie ALPR
        $out = assignPhoto($app->id, 'contextImage', $s['photoId'], $user);
        $out = assignPhoto($app->id, 'carImage', $s['photoId'], $user, null, 'vehicle');
        $this->assertSame(1, $this->alprCalls, 'kontekst + wycinek auta z jednego zdjęcia = jedno wywołanie ALPR');

        foreach (['carImage', 'contextImage'] as $slot) {
            $this->paths[] = ROOT . $out->$slot->url;
            $this->paths[] = ROOT . $out->$slot->thumb;
        }
        if (isset($out->carInfo->plateImage)) $this->paths[] = ROOT . $out->carInfo->plateImage;

        // kontekst = całe zdjęcie (2000x1000 → ≤1600), auto = wycinek 2000x940 (box 10–90% x 20–90% +20%, docięty) → ≤1600
        $this->assertSame(1600, $out->contextImage->width);
        $this->assertSame([1600, 752], [$out->carImage->width, $out->carImage->height]);
        $this->assertSame('ZS12345', $out->carInfo->plateId);
        $this->assertFileExists(ROOT . $out->carInfo->plateImage, 'wycinek tablicy z wycinka auta');
        $this->assertLessThanOrEqual(1600, $out->carInfo->vehicleBox->x + $out->carInfo->vehicleBox->width);
        $this->assertSame($s['photoId'], $out->wipPhotos->carImage);
        $this->assertSame($s['photoId'], $out->wipPhotos->contextImage);
    }

    private function twoCars(): void
    {
        // zwycięzca: duże auto z przodu (BG11111); drugie, wyraźnie odczytane auto z boku kadru (ZS22222)
        \vision\PlateRecognizerClient::set(function (string $bytes): array {
            $this->alprCalls++;
            [$w, $h] = getimagesizefromstring($bytes);
            $box = fn (float $x1, float $y1, float $x2, float $y2): array => ['xmin' => (int)($w * $x1), 'ymin' => (int)($h * $y1), 'xmax' => (int)($w * $x2), 'ymax' => (int)($h * $y2)];
            return ['results' => [
                ['plate' => 'bg11111', 'score' => 0.99, 'box' => $box(0.30, 0.70, 0.40, 0.75), 'vehicle' => ['box' => $box(0.2, 0.3, 0.6, 0.9)]],
                ['plate' => 'zs22222', 'score' => 0.95, 'box' => $box(0.75, 0.55, 0.80, 0.58), 'vehicle' => ['box' => $box(0.7, 0.4, 0.95, 0.65)]],
            ]];
        });
    }

    public function testOnlyPlateNarrowsTheResultToTheChosenVehicle(): void
    {
        $resp = ['results' => [['plate' => 'AB 123'], ['plate' => 'zs-22222']], 'filename' => 'f'];
        $this->assertSame('zs-22222', \alpr\onlyPlate($resp, 'ZS22222')['results'][0]['plate']);
        $this->assertCount(1, \alpr\onlyPlate($resp, 'ab123')['results']);
        $this->assertSame('f', \alpr\onlyPlate($resp, 'ab123')['filename']);
        $this->assertNull(\alpr\onlyPlate($resp, 'XX9999'));
    }

    public function testAssignFollowsTheVehicleChosenByTheUser(): void
    {
        $user = $this->user('wip-choose@example.com');
        $app = $this->draft($user);
        $s = \wip\stage($user, $this->jpeg(1600, 1000));
        $this->track($s['photoId'], $user);
        $this->twoCars();

        \wip\detections($user, $s['photoId']);
        $out = assignPhoto($app->id, 'carImage', $s['photoId'], $user, null, null, 'ZS22222');
        $this->assertSame('ZS22222', $out->carInfo->plateId, 'wskazane auto zamiast zwycięzcy zdjęcia');
        $this->assertSame(1, $this->alprCalls);

        $out = assignPhoto($app->id, 'carImage', $s['photoId'], $user, null, 'vehicle', 'ZS22222');
        foreach (['carImage'] as $slot) { $this->paths[] = ROOT . $out->$slot->url; $this->paths[] = ROOT . $out->$slot->thumb; }
        if (isset($out->carInfo->plateImage)) $this->paths[] = ROOT . $out->carInfo->plateImage;
        $this->assertSame('ZS22222', $out->carInfo->plateId);
        // wycinek = wskazane auto (25% szerokości kadru + marginesy), nie zwycięzca (40%)
        $this->assertSame(1, $this->alprCalls);
        $this->assertLessThan(1000, $out->carImage->width);

        $out = assignPhoto($app->id, 'carImage', $s['photoId'], $user, null, null, 'XX0000'); // nieznana tablica → zwycięzca
        $this->paths[] = ROOT . $out->carImage->url; $this->paths[] = ROOT . $out->carImage->thumb;
        if (isset($out->carInfo->plateImage)) $this->paths[] = ROOT . $out->carInfo->plateImage;
        $this->assertSame('BG11111', $out->carInfo->plateId);
    }

    public function testMapResultToRegionRescalesBoxesToTheCropResolution(): void
    {
        // źródło 1600x1000; region = prawa górna ćwiartka kadru [0.5,0,1,0.5] = 800x500 px; wycinek z oryginału ma 2x więcej pikseli (1600x1000)
        $resp = ['results' => [['plate' => 'zs1', 'score' => 1,
            'box' => ['xmin' => 1000, 'ymin' => 100, 'xmax' => 1200, 'ymax' => 160],
            'vehicle' => ['box' => ['xmin' => 900, 'ymin' => 50, 'xmax' => 1500, 'ymax' => 400]]]]];
        $out = \alpr\mapPlateRecognizerResultToRegion($resp, 1600, 1000, [0.5, 0.0, 1.0, 0.5], 1600, 1000);
        $this->assertSame(['xmin' => 400, 'ymin' => 200, 'xmax' => 800, 'ymax' => 320], $out['results'][0]['box']);
        $this->assertSame(['xmin' => 200, 'ymin' => 100, 'xmax' => 1400, 'ymax' => 800], $out['results'][0]['vehicle']['box']);
    }

    public function testDerivedCropFromOriginalReusesAlprAndInheritsMeta(): void
    {
        $user = $this->user('wip-derived@example.com');
        $src = \wip\stage($user, $this->jpeg(2000, 1000), ['dateTime' => '2026-09-10T19:43:00', 'lat' => 53.43, 'lng' => 14.55]);
        $this->track($src['photoId'], $user);
        \wip\detections($user, $src['photoId']); // analiza: jedyne wywołanie ALPR

        // klient tnie z oryginału (np. 4x większego) obszar pojazdu: [0.1,0.2,0.9,0.9] → wycinek 3200x1400 px
        $d = \wip\stageDerived($user, $this->jpeg(3200, 1400), $src['photoId'], [0.1, 0.2, 0.9, 0.9], 'ZS12345');
        $this->track($d['photoId'], $user);
        $this->assertSame([3200, 1400], [$d['width'], $d['height']]);
        $this->assertSame(1, $this->alprCalls);

        $meta = \wip\load($user, $d['photoId'])['meta'];
        $this->assertSame($src['photoId'], $meta['derivedFrom']);
        $this->assertSame(53.43, $meta['lat']);
        $this->assertSame('2026-09-10T19:43:00', $meta['dateTime']);
        // box pojazdu = cały wycinek, box tablicy (40–60% x 70–80% źródła) przeliczony na rozdzielczość wycinka
        $this->assertSame(['xmin' => 0, 'ymin' => 0, 'xmax' => 3200, 'ymax' => 1400], $meta['alpr']['results'][0]['vehicle']['box']);
        $this->assertSame(['xmin' => 1200, 'ymin' => 1000, 'xmax' => 2000, 'ymax' => 1200], $meta['alpr']['results'][0]['box']);

        $this->expectExceptionCode(400);
        \wip\stageDerived($user, $this->jpeg(100, 100), $src['photoId'], [0.9, 0.9, 1.2, 1.2]);
    }

    public function testAssignDerivedCarCropAndReplacePlateImage(): void
    {
        $user = $this->user('wip-plate@example.com');
        $app = $this->draft($user);
        $src = \wip\stage($user, $this->jpeg(2000, 1000));
        $this->track($src['photoId'], $user);
        \wip\detections($user, $src['photoId']);
        $car = \wip\stageDerived($user, $this->jpeg(1900, 840), $src['photoId'], [0.1, 0.2, 0.9, 0.9]);
        $this->track($car['photoId'], $user);

        $out = assignPhoto($app->id, 'contextImage', $src['photoId'], $user);
        $out = assignPhoto($app->id, 'carImage', $car['photoId'], $user);
        $this->assertSame(1, $this->alprCalls, 'kontekst + wycinek z oryginału = jedno wywołanie ALPR');
        $this->assertSame('ZS12345', $out->carInfo->plateId);
        $this->assertSame(1600, $out->carImage->width); // wycinek 1900 px zmniejszony do limitu zapisu

        $before = ROOT . $out->carInfo->plateImage;
        $this->paths[] = $before; $this->paths[] = ROOT . $out->carImage->url; $this->paths[] = ROOT . $out->carImage->thumb;
        $this->paths[] = ROOT . $out->contextImage->url; $this->paths[] = ROOT . $out->contextImage->thumb;

        // tablica z oryginału (np. 1200x300 px) – serwer ogranicza do 800 px szerokości i podmienia plik
        $out = replacePlateImage($app->id, $this->jpeg(1200, 300), $user);
        $this->assertSame($before, ROOT . $out->carInfo->plateImage);
        $this->assertSame([800, 200], array_slice(getimagesize($before), 0, 2));

        $this->expectExceptionCode(403);
        replacePlateImage($app->id, $this->jpeg(10, 10), $this->user('wip-plate-other@example.com'));
    }

    public function testAssignWithVehicleCropFailsWithoutAVehicle(): void
    {
        $user = $this->user('wip-nocar@example.com');
        $app = $this->draft($user);
        $s = \wip\stage($user, $this->jpeg(800, 600));
        $this->track($s['photoId'], $user);
        \vision\PlateRecognizerClient::set(fn (string $b): array => ['results' => []]);

        $this->expectException(\ValidationException::class);
        assignPhoto($app->id, 'carImage', $s['photoId'], $user, null, 'vehicle');
    }

    public function testPurgeOlderThanRemovesOnlyStaleFiles(): void
    {
        $user = $this->user('wip-purge@example.com');
        $old = \wip\stage($user, $this->jpeg(100, 100));
        $new = \wip\stage($user, $this->jpeg(100, 100));
        $this->track($old['photoId'], $user);
        $this->track($new['photoId'], $user);
        touch(\wip\photoPath($user, $old['photoId']), time() - 30 * 3600);
        touch(\wip\sidecarPath($user, $old['photoId']), time() - 30 * 3600);

        $this->assertGreaterThanOrEqual(2, \wip\purgeOlderThan(24));
        $this->assertNull(\wip\load($user, $old['photoId']));
        $this->assertNotNull(\wip\load($user, $new['photoId']));
    }
}
