<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/integrations/MailGun.php';
require_once __DIR__ . '/../../export/inc/mcp/ReportMcpTools.php';

use app\Application;
use Aws\MockHandler;
use Aws\Result;
use store\S3;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UploadedFileFactory;
use UprzejmieDonosze\Tests\DatabaseTestCase;
use user\User;

/**
 * Zgłoszenia „na gorąco” (kategorie `adHoc`: hałas/wydech, drift) i kategorie stanu technicznego (`beta`):
 * brak wymaganych zdjęć, wysyłka na wojewódzką skrzynkę „Stop agresji drogowej”, nagranie wideo, treści bez
 * wzmianek o parkowaniu, ukrycie przed MCP/produkcją.
 */
class AdHocReportTest extends DatabaseTestCase
{
    private const NOISE = 31;   // Hałas i spaliny w czasie jazdy (adHoc)
    private const DRIFT = 32;   // Drift lub jazda na jednym kole (adHoc)
    private const LEAK = 28;    // Wyciek płynów (beta, zwykła lista, tylko Policja)

    protected function tearDown(): void
    {
        \storage\b2(new S3('reset', 'k', 's', 'https://example.test', 'us-east-1')); // drop the mock
        parent::tearDown();
    }

    private function savedUser(string $email = 'adhoc-test@example.com'): User
    {
        $_SESSION['user_email'] = $email;
        $_SESSION['user_id'] = 'phpunit-user-id';
        $user = new User();
        $user->email = $email;
        $user->data->name = 'Test User';
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

    /** Szczecin: Police::guess() trafia tu w konkretny komisariat (PoliceStationAreas) – „na gorąco” ma go pominąć. */
    private function szczecin(): \JSONObject
    {
        $a = new \JSONObject();
        foreach (['address' => 'Mazurska 37, Szczecin', 'city' => 'Szczecin', 'voivodeship' => 'zachodniopomorskie',
                  'lat' => 53.43, 'lng' => 14.55] as $k => $v)
            $a->$k = $v;
        return $a;
    }

    private function now(string $modify = 'now'): string
    {
        return (new \DateTime())->modify($modify)->format('Y-m-d\TH:i:s');
    }

    /** Najmniejszy plik, który finfo rozpoznaje jako video/mp4 (pudełko `ftyp`). */
    private function mp4Bytes(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41" . str_repeat("\x00", 64);
    }

    private function videoRequest(string $bytes, ?int $size = null, int $error = UPLOAD_ERR_OK): \Psr\Http\Message\ServerRequestInterface
    {
        $file = (new UploadedFileFactory())->createUploadedFile(
            (new StreamFactory())->createStream($bytes), $size ?? strlen($bytes), $error, 'v.mp4', 'video/mp4');
        return (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/rest/app/x/video')
            ->withUploadedFiles(['video' => $file]);
    }

    public function testCategoryFlags(): void
    {
        global $CATEGORIES;
        foreach ([self::NOISE, self::DRIFT] as $id) {
            $this->assertTrue($CATEGORIES[$id]->isAdHoc(), "$id adHoc");
            $this->assertTrue($CATEGORIES[$id]->isStopAgresjiOnly());
            $this->assertFalse($CATEGORIES[$id]->isParking());
            $this->assertTrue($CATEGORIES[$id]->isBeta());
            $this->assertObjectNotHasProperty('groups', $CATEGORIES[$id], 'adHoc nie może trafić na zwykłą listę');
        }
        foreach ([27, 28, 29, 30] as $id) {
            $this->assertFalse($CATEGORIES[$id]->isAdHoc(), "$id adHoc");
            $this->assertTrue($CATEGORIES[$id]->isStopAgresjiOnly(), "$id tylko Policja");
            $this->assertFalse($CATEGORIES[$id]->isParking());
            // starsze wersje aplikacji i web pokazują kategorie z `groups` – testowe mają je w `betaGroups`
            $this->assertObjectNotHasProperty('groups', $CATEGORIES[$id]);
            $this->assertSame([6], (array)$CATEGORIES[$id]->betaGroups);
        }
        $this->assertTrue($CATEGORIES[5]->isParking());
        $this->assertTrue($CATEGORIES[5]->isAvailable());
        $this->assertFalse($CATEGORIES[5]->isAdHoc());
    }

    public function testBetaCategoriesAreUnavailableInProduction(): void
    {
        global $CATEGORIES;
        $old = getenv('APP_ENV');
        try {
            putenv('APP_ENV=prod');
            $this->assertFalse(\Category::betaEnabled());
            $this->assertFalse($CATEGORIES[self::NOISE]->isAvailable());
            $this->assertFalse($CATEGORIES[self::LEAK]->isAvailable());
            $this->assertTrue($CATEGORIES[5]->isAvailable());

            $user = $this->savedUser();
            $app = $this->draft($user);
            try {
                updateApplication($app, $this->now(), false, self::NOISE, $this->szczecin(), 'ZS12345', '', true, [], $user);
                $this->fail('kategoria beta nie może przejść na produkcji');
            } catch (\ValidationException $e) {
                $this->assertSame('category', $e->getField());
            }
        } finally {
            putenv($old === false ? 'APP_ENV' : "APP_ENV=$old");
        }
        $this->assertTrue($CATEGORIES[self::NOISE]->isAvailable());
    }

    public function testUnknownCategoryIsRejected(): void
    {
        $user = $this->savedUser();
        $app = $this->draft($user);
        $this->expectException(\ValidationException::class);
        updateApplication($app, $this->now(), false, 999, $this->szczecin(), 'ZS12345', '', true, [], $user);
    }

    public function testAdHocNeedsNoImages(): void
    {
        $user = $this->savedUser();
        $app = $this->draft($user);
        $this->assertFalse($app->hasRequiredImages(self::LEAK));
        $this->assertTrue($app->hasRequiredImages(self::NOISE));
        $this->assertFalse($app->hasRequiredImages(5));
    }

    public function testAdHocGoesToVoivodeshipStopAgresjiNotToPoliceStation(): void
    {
        $user = $this->savedUser();

        // dla porównania: zwykła kategoria „tylko Policja” w Szczecinie trafia do konkretnego komisariatu
        $this->assertNotNull(\Police::guess($this->szczecin()));

        $app = $this->draft($user);
        $saved = updateApplication($app, $this->now('-10 minutes'), false, self::NOISE, $this->szczecin(),
            'ZS 12345', 'Strzały z wydechu', true, [], $user);

        $this->assertSame('ready', $saved->status);
        $this->assertTrue($saved->stopAgresji());
        $this->assertTrue($saved->stopAgresjiForced);
        $this->assertSame('zachodniopomorskie', $saved->smCity);
        $sm = $saved->guessSMData(true); // jak CityAPI::checkApplication() tuż przed wysyłką – wynik nie może się zmienić
        $this->assertSame('zachodniopomorskie', $saved->smCity);
        $this->assertTrue($sm->isPolice());
        $this->assertStringStartsWith('stopagresjidrogowej', $sm->getEmail());
        $this->assertTrue($sm->automated());

        // bez zdjęć zgłoszenie da się potwierdzić
        $finished = finishApplication($saved->id, $user);
        $this->assertSame('confirmed', $finished['application']->status);
    }

    public function testAdHocRejectsOldDate(): void
    {
        $user = $this->savedUser();
        $app = $this->draft($user);
        try {
            updateApplication($app, $this->now('-2 days'), false, self::DRIFT, $this->szczecin(), 'ZS12345', '', true, [], $user);
            $this->fail('zgłoszenie na gorąco sprzed 2 dni musi zostać odrzucone');
        } catch (\ValidationException $e) {
            $this->assertSame('datetime', $e->getField());
        }
    }

    public function testVideoOnlyForAdHocCategories(): void
    {
        $user = $this->savedUser();
        $app = $this->draft($user);
        foreach (['contextImage', 'carImage'] as $slot) {
            $app->$slot = new \stdClass();
            $app->$slot->url = "cdn2/x/{$app->id},$slot.jpg";
        }
        $app->video = new \stdClass();
        $app->video->url = "cdn2/x/{$app->id},vi.mp4";
        \app\save($app);
        try {
            updateApplication($app, $this->now(), false, self::LEAK, $this->szczecin(), 'ZS12345', '', true, [], $user);
            $this->fail('nagranie przy zwykłej kategorii musi zostać odrzucone');
        } catch (\ValidationException $e) {
            $this->assertSame('video', $e->getField());
        }
    }

    public function testVideoUploadValidation(): void
    {
        $up = videoUploadFromRequest($this->videoRequest($this->mp4Bytes()));
        try {
            $this->assertSame('video/mp4', $up['mime']);
            $this->assertFileExists($up['path']);
        } finally {
            @unlink($up['path']);
        }

        try {
            videoUploadFromRequest($this->videoRequest($this->mp4Bytes(), MAX_VIDEO_UPLOAD_BYTES + 1));
            $this->fail('za duże nagranie');
        } catch (\ValidationException $e) {
            $this->assertSame('video', $e->getField());
        }

        try {
            videoUploadFromRequest($this->videoRequest('', 0, UPLOAD_ERR_INI_SIZE));
            $this->fail('za duże nagranie (limit PHP)');
        } catch (\ValidationException $e) {
            $this->assertSame('video', $e->getField());
        }

        try {
            videoUploadFromRequest($this->videoRequest("\xFF\xD8\xFF\xE0" . str_repeat('x', 100)));
            $this->fail('JPEG zamiast filmu');
        } catch (\ValidationException $e) {
            $this->assertSame('video', $e->getField());
        }

        $this->expectException(\MissingParamException::class);
        videoUploadFromRequest((new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/x'));
    }

    public function testAttachAndRemoveVideo(): void
    {
        $user = $this->savedUser();
        $app = $this->draft($user);
        $tmp = tempnam(sys_get_temp_dir(), 'udtest');
        file_put_contents($tmp, $this->mp4Bytes());

        $withVideo = attachVideo($app->id, $tmp, 'video/mp4', $user);
        $this->assertFileDoesNotExist($tmp, 'plik tymczasowy sprzątnięty');
        $this->assertTrue($withVideo->hasVideo());
        $this->assertStringEndsWith(",vi.mp4", $withVideo->video->url);
        $this->assertSame(strlen($this->mp4Bytes()), $withVideo->video->size);
        $local = ROOT . $withVideo->video->url;
        $this->assertFileExists($local);
        $this->assertTrue(\app\get($app->id)->hasVideo(), 'zapisane w bazie');

        \storage\b2(new S3('test', 'k', 's', 'https://example.test', 'us-east-1',
            ['handler' => new MockHandler([new Result([])])]));
        $removed = removeApplicationVideo(\app\get($app->id));
        $this->assertFalse($removed->hasVideo());
        $this->assertFileDoesNotExist($local);
    }

    public function testAttachVideoChecksOwnership(): void
    {
        $owner = $this->savedUser('adhoc-owner@example.com');
        $app = $this->draft($owner);
        $other = $this->savedUser('adhoc-other@example.com');
        $tmp = tempnam(sys_get_temp_dir(), 'udtest');
        file_put_contents($tmp, $this->mp4Bytes());
        try {
            attachVideo($app->id, $tmp, 'video/mp4', $other);
            $this->fail('cudze zgłoszenie');
        } catch (\Exception $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertFileDoesNotExist($tmp);
    }

    public function testTextsForAdHocReportWithVideoOnly(): void
    {
        $user = $this->savedUser();
        $app = updateApplication($this->draft($user), $this->now(), false, self::NOISE, $this->szczecin(),
            'ZS12345', '', true, [], $user);
        $app->video = new \stdClass();
        $app->video->url = "cdn2/x/{$app->id},vi.mp4";
        $app->video->mime = 'video/mp4';
        $app->video->size = 123;

        $email = (new \MailGun())->formatEmail($app, false);
        $this->assertStringContainsString('W załączeniu znajduje się zgłoszenie oraz nagranie wideo.', $email);
        $this->assertStringContainsString('samodzielnie i nie zostało ono zmanipulowane', $email);
        $this->assertStringContainsString('art. 60 ust. 2 pkt 2 prd', $email);
        $this->assertStringNotContainsString('parkowania', $email);
        $this->assertStringNotContainsString('agendaparkingowa', $email);
        $this->assertStringNotContainsString('pliku zip', $email);

        $confirmation = confirmationData($app);
        $this->assertStringContainsString('naocznym świadkiem zdarzenia', $confirmation['witness']);
        $this->assertSame(['size' => 123, 'mime' => 'video/mp4'], $confirmation['video']);
    }

    public function testTextsForAdHocReportWithoutMaterial(): void
    {
        $user = $this->savedUser();
        $app = updateApplication($this->draft($user), $this->now(), false, self::DRIFT, $this->szczecin(),
            'ZS12345', '', true, [], $user);
        $email = (new \MailGun())->formatEmail($app, false);
        $this->assertStringContainsString('W załączeniu znajduje się zgłoszenie.', $email);
        $this->assertStringContainsString('relacji naocznego świadka', $email);
        $this->assertStringNotContainsString('Zdjęcia', $email);
    }

    public function testParkingReportTextUnchanged(): void
    {
        $user = $this->savedUser();
        $app = $this->draft($user);
        foreach (['contextImage', 'carImage'] as $slot) {
            $app->$slot = new \stdClass();
            $app->$slot->url = "cdn2/x/{$app->id},$slot.jpg";
        }
        \app\save($app);
        $app = updateApplication($app, $this->now(), false, 5, $this->szczecin(), 'ZS12345', '', false, [], $user);
        $email = (new \MailGun())->formatEmail($app, false);
        $this->assertStringContainsString('W załączeniu znajduje się zgłoszenie oraz zdjęcia w pliku zip. Jestem', $email);
        $this->assertStringContainsString('Zdjęcia ' . $app->guessUserSex()['wykonalam'] . ' samodzielnie i nie zostały one zmanipulowane.', $email);
        $this->assertStringContainsString('świadkiem samego momentu parkowania', $email);
        $this->assertStringContainsString('agendaparkingowa.pl', $email);
        $this->assertStringContainsString('świadkiem parkowania', confirmationData($app)['witness']);
    }

    public function testStopAgresjiVoivodeshipFallbacks(): void
    {
        $a = new \JSONObject();
        $a->city = 'Wąchock';
        $a->voivodeship = 'świętokrzyskie';
        $this->assertSame('świętokrzyskie', \StopAgresji::guessVoivodeship($a));
        $a->voivodeship = null;
        $this->assertSame('default', \StopAgresji::guessVoivodeship($a));
    }

    public function testMcpHidesAdHocCategories(): void
    {
        global $CATEGORIES;
        $ids = array_map('intval', \mcp\ReportMcpTools::sortedCategoryIds($CATEGORIES));
        $this->assertNotContains(self::NOISE, $ids);
        $this->assertNotContains(self::DRIFT, $ids);
        $this->assertContains(5, $ids);
    }
}
