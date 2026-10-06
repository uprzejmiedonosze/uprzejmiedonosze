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
