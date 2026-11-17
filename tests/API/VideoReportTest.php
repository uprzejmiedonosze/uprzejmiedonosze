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
 * Zgłoszenia z nagraniem: hałas/wydech i drift (`requiresVideo`) wymagają filmu i idą na wojewódzką skrzynkę
 * „Stop agresji drogowej”; nagranie można dołączyć do każdej kategorii (wtedy najwyżej dwa zdjęcia); kategorie stanu
 * technicznego są na zwykłej liście (grupa 6). Web i MCP nie pokazują kategorii wymagających nagrania.
 */
class VideoReportTest extends DatabaseTestCase
{
    private const NOISE = 31;   // Hałas i spaliny w czasie jazdy (requiresVideo)
    private const DRIFT = 32;   // Drift lub jazda na jednym kole (requiresVideo)
    private const LEAK = 28;    // Wyciek płynów (zwykła lista, tylko Policja)

    protected function tearDown(): void
    {
        \storage\b2(new S3('reset', 'k', 's', 'https://example.test', 'us-east-1')); // drop the mock
        parent::tearDown();
    }

    private function savedUser(string $email = 'video-test@example.com'): User
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

    /** Szczecin: Police::guess() trafia tu w konkretny komisariat (PoliceStationAreas) – hałas/drift mają go pominąć. */
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

    /** Zdjęcia kontekstu i auta (jak po przydziale w aplikacji); `$third` = także dodatkowe. */
    private function withImages(Application $app, bool $third = false): Application
    {
        foreach (array_merge(['contextImage', 'carImage'], $third ? ['thirdImage'] : []) as $slot) {
            $app->$slot = new \stdClass();
            $app->$slot->url = "cdn2/x/{$app->id},$slot.jpg";
        }
        \app\save($app);
        return $app;
    }

    private function withVideo(Application $app): Application
    {
        $app->video = new \stdClass();
        $app->video->url = "cdn2/x/{$app->id},vi.mp4";
        $app->video->mime = 'video/mp4';
        $app->video->size = 123;
        \app\save($app);
        return $app;
    }

    public function testCategoryFlags(): void
    {
        global $CATEGORIES;
        foreach ([self::NOISE, self::DRIFT] as $id) {
            $this->assertTrue($CATEGORIES[$id]->requiresVideo(), "$id requiresVideo");
            $this->assertTrue($CATEGORIES[$id]->isStopAgresjiOnly());
            $this->assertFalse($CATEGORIES[$id]->isParking());
            $this->assertSame([6], (array)$CATEGORIES[$id]->groups);
        }
        foreach ([27, 28, 29, 30] as $id) {
            $this->assertFalse($CATEGORIES[$id]->requiresVideo(), "$id requiresVideo");
            $this->assertTrue($CATEGORIES[$id]->isStopAgresjiOnly(), "$id tylko Policja");
            $this->assertFalse($CATEGORIES[$id]->isParking());
            $this->assertSame([6], (array)$CATEGORIES[$id]->groups);
        }
        foreach ($CATEGORIES as $id => $c) {
            $this->assertObjectNotHasProperty('beta', $c, "$id: bez flag testowych");
            $this->assertObjectNotHasProperty('betaGroups', $c, "$id: bez flag testowych");
        }
        $this->assertTrue($CATEGORIES[5]->isParking());
        $this->assertFalse($CATEGORIES[5]->requiresVideo());
    }

    public function testUnknownCategoryIsRejected(): void
    {
        $user = $this->savedUser();
        $app = $this->withImages($this->draft($user));
        $this->expectException(\ValidationException::class);
        updateApplication($app, $this->now(), false, 999, $this->szczecin(), 'ZS12345', '', true, [], $user);
    }

    public function testEveryCategoryNeedsPhotos(): void
    {
        $user = $this->savedUser();
        $app = $this->withVideo($this->draft($user));
        $this->assertFalse($app->hasRequiredImages());
        $this->expectException(\NotSendableException::class);
        updateApplication($app, $this->now(), false, self::NOISE, $this->szczecin(), 'ZS12345', '', true, [], $user);
    }

    public function testVideoCategoryNeedsVideo(): void
    {
        $user = $this->savedUser();
        $app = $this->withImages($this->draft($user));
        try {
            updateApplication($app, $this->now(), false, self::DRIFT, $this->szczecin(), 'ZS12345', '', true, [], $user);
            $this->fail('drift bez nagrania musi zostać odrzucony');
        } catch (\ValidationException $e) {
            $this->assertSame('video', $e->getField());
        }
    }

    public function testVideoCategoryGoesToVoivodeshipStopAgresjiNotToPoliceStation(): void
    {
        $user = $this->savedUser();

        // dla porównania: zwykła kategoria „tylko Policja” w Szczecinie trafia do konkretnego komisariatu
        $this->assertNotNull(\Police::guess($this->szczecin()));

        // bez limitu 24 h – obowiązuje zwykłe 7 miesięcy
        $app = $this->withVideo($this->withImages($this->draft($user)));
        $saved = updateApplication($app, $this->now('-3 days'), false, self::NOISE, $this->szczecin(),
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
    }

    public function testVideoAllowedOnAnyCategory(): void
    {
        $user = $this->savedUser();
        $app = $this->withVideo($this->withImages($this->draft($user)));
        $saved = updateApplication($app, $this->now('-2 days'), false, self::LEAK, $this->szczecin(), 'ZS12345', '', true, [], $user);
        $this->assertSame('ready', $saved->status);
        $this->assertNotSame('zachodniopomorskie', $saved->smCity, 'zwykła kategoria idzie do komisariatu, nie na skrzynkę wojewódzką');
    }

    public function testVideoAllowsAtMostTwoPhotos(): void
    {
        $user = $this->savedUser();
        $app = $this->withVideo($this->withImages($this->draft($user), true));
        try {
            updateApplication($app, $this->now(), false, self::NOISE, $this->szczecin(), 'ZS12345', '', true, [], $user);
            $this->fail('nagranie + trzy zdjęcia');
        } catch (\ValidationException $e) {
            $this->assertSame('images', $e->getField());
        }
        // bez nagrania trzy zdjęcia są w porządku
        $plain = $this->withImages($this->draft($user), true);
        $this->assertSame('ready', updateApplication($plain, $this->now(), false, self::LEAK, $this->szczecin(), 'ZS12345', '', true, [], $user)->status);
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

        // attachVideo() od razu wysyła nagranie do B2 (PutObject), a removeApplicationVideo() je stamtąd usuwa
        $b2 = new MockHandler([new Result([]), new Result([])]);
        \storage\b2(new S3('test', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $b2]));
        $withVideo = attachVideo($app->id, $tmp, 'video/mp4', $user);
        $this->assertSame(1, 2 - count($b2), 'nagranie wysłane do B2');
        $this->assertFileDoesNotExist($tmp, 'plik tymczasowy sprzątnięty');
        $this->assertTrue($withVideo->hasVideo());
        $this->assertStringEndsWith(",vi.mp4", $withVideo->video->url);
        $this->assertSame(strlen($this->mp4Bytes()), $withVideo->video->size);
        $local = ROOT . $withVideo->video->url;
        $this->assertFileExists($local);
        $this->assertTrue(\app\get($app->id)->hasVideo(), 'zapisane w bazie');

        $removed = removeApplicationVideo(\app\get($app->id));
        $this->assertFalse($removed->hasVideo());
        $this->assertFileDoesNotExist($local);
    }

    public function testAttachVideoChecksOwnership(): void
    {
        $owner = $this->savedUser('video-owner@example.com');
        $app = $this->draft($owner);
        $other = $this->savedUser('video-other@example.com');
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

    public function testTextsForVideoReport(): void
    {
        $user = $this->savedUser();
        $app = $this->withVideo($this->withImages($this->draft($user)));
        $app = updateApplication($app, $this->now(), false, self::NOISE, $this->szczecin(), 'ZS12345', '', true, [], $user);

        $email = (new \MailGun())->formatEmail($app, false);
        // nagranie nie jest załącznikiem – link przez /cdn2/ (lokalnie albo proxy z B2)
        $this->assertStringContainsString('W załączeniu znajduje się zgłoszenie oraz zdjęcia w pliku zip. Nagranie wideo zdarzenia: ' . BASE_URL . "cdn2/x/{$app->id},vi.mp4", $email);
        $this->assertStringContainsString('Zdjęcia i nagranie ' . $app->guessUserSex()['wykonalam'] . ' samodzielnie', $email);
        $this->assertStringContainsString('art. 60 ust. 2 pkt 2 prd', $email);
        $this->assertStringNotContainsString('parkowania', $email);
        $this->assertStringNotContainsString('agendaparkingowa', $email);

        $confirmation = confirmationData($app);
        $this->assertStringContainsString('naocznym świadkiem zdarzenia', $confirmation['witness']);
        $this->assertSame(['size' => 123, 'mime' => 'video/mp4', 'url' => BASE_URL . "cdn2/x/{$app->id},vi.mp4"], $confirmation['video']);
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

    public function testMcpHidesVideoCategories(): void
    {
        global $CATEGORIES;
        $ids = array_map('intval', \mcp\ReportMcpTools::sortedCategoryIds($CATEGORIES));
        $this->assertNotContains(self::NOISE, $ids);
        $this->assertNotContains(self::DRIFT, $ids);
        $this->assertContains(5, $ids);
    }
}
