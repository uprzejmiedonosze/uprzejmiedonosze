<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/handlers/SessionApiHandler.php';

use app\Application;
use Aws\MockHandler;
use Aws\Result;
use Slim\Psr7\Factory\ServerRequestFactory;
use store\S3;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UploadedFileFactory;
use UprzejmieDonosze\Tests\DatabaseTestCase;
use user\User;

/**
 * Shared building blocks of the report-creation flow used by BOTH the cookie API
 * (/api/app/*) and the JWT REST API (/api/rest/app/*): upload parsing, image
 * removal, server-side field validation, finish ("Potwierdź") and `recipient`.
 */
class ReportFlowTest extends DatabaseTestCase
{
    private function savedUser(string $email = 'report-flow-test@example.com'): User
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

    /** An application that already has both required photos (fake file names, no disk IO). */
    private function appWithImages(User $user): Application
    {
        $app = Application::withUser($user);
        $app->statusHistory = [];
        $app->comments = [];
        $app->extensions = [];
        foreach (['contextImage', 'carImage'] as $slot) {
            $app->$slot = new \stdClass();
            $app->$slot->url = "cdn2/x/{$app->id},$slot.jpg";
            $app->$slot->thumb = "cdn2/x/{$app->id},$slot,t.jpg";
        }
        \app\save($app);
        return $app;
    }

    protected function tearDown(): void
    {
        \storage\b2(new S3('reset', 'k', 's', 'https://example.test', 'us-east-1')); // drop the mock
        parent::tearDown();
    }

    private function request(array $body, array $files = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/rest/app/x/image')
            ->withParsedBody($body)
            ->withUploadedFiles($files);
    }

    private function upload(string $bytes): \Psr\Http\Message\UploadedFileInterface
    {
        return (new UploadedFileFactory())->createUploadedFile(
            (new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'a.jpg', 'image/jpeg');
    }

    public function testMultipartUploadWithMetadata(): void
    {
        $up = imageUploadFromRequest($this->request(
            ['pictureType' => 'carImage', 'dateTime' => '2026-09-10T19:43:00', 'dtFromPicture' => 'true', 'lat' => '53.4', 'lng' => '14.5'],
            ['image' => $this->upload('jpegbytes')]
        ));
        $this->assertSame('jpegbytes', $up['bytes']);
        $this->assertSame('carImage', $up['pictureType']);
        $this->assertSame('2026-09-10T19:43:00', $up['dateTime']);
        $this->assertTrue($up['dtFromPicture']);
        $this->assertSame(\geo\normalizeLatLng('53.4', '14.5'), $up['latLng']);
    }

    public function testLegacyRestDataUriFieldImpliesPictureType(): void
    {
        $uri = 'data:image/jpeg;base64,' . base64_encode('legacy');
        $up = imageUploadFromRequest($this->request(['thirdImage' => $uri, 'dateTime' => '2026-09-10T19:43:00']));
        $this->assertSame('legacy', $up['bytes']);
        $this->assertSame('thirdImage', $up['pictureType']);
        $this->assertTrue($up['dtFromPicture']); // legacy contract: implied by dateTime
    }

    public function testUploadRequiresPictureType(): void
    {
        $this->expectException(\MissingParamException::class);
        imageUploadFromRequest($this->request([], ['image' => $this->upload('x')]));
    }

    public function testRemoveApplicationImageUnsetsSlotAndRejectsUnknown(): void
    {
        // removal also deletes from B2 – keep the test hermetic with a mocked S3 (pattern: StorageTest)
        \storage\b2(new S3('test', 'k', 's', 'https://example.test', 'us-east-1',
            ['handler' => new MockHandler([new Result([]), new Result([])])]));

        $app = $this->appWithImages($this->savedUser());
        $app = removeApplicationImage($app, 'carImage');
        $this->assertFalse(isset($app->carImage));
        $this->assertTrue(isset($app->contextImage));

        $this->expectException(\Exception::class);
        removeApplicationImage($app, 'user');
    }

    public function testUpdateValidatesFieldsOnTheServer(): void
    {
        $user = $this->savedUser();
        $app = $this->appWithImages($user);
        $address = new \JSONObject();
        $address->address = 'Mazurska 37, Szczecin';
        $address->city = 'Szczecin';
        $address->lat = 53.43;
        $address->lng = 14.55;
        $address->voivodeship = 'zachodniopomorskie';

        $cases = [
            'plateId' => ['AB', '2026-09-10T19:43:00', $address, 8, 'x'],
            'datetime' => ['ZS12345', 'to-nie-data', $address, 8, 'x'],
            'comment' => ['ZS12345', '2026-09-10T19:43:00', $address, 0, ''], // category 0 needs a comment
        ];
        foreach ($cases as $field => [$plate, $date, $addr, $category, $comment]) {
            try {
                updateApplication($app, $date, true, $category, $addr, $plate, $comment, false, [], $user);
                $this->fail("expected ValidationException for $field");
            } catch (\ValidationException $e) {
                $this->assertSame($field, $e->getField());
                $this->assertSame(422, $e->getCode());
            }
        }

        $noAddress = new \JSONObject();
        $noAddress->address = '  ';
        $this->expectException(\ValidationException::class);
        updateApplication($app, '2026-09-10T19:43:00', true, 8, $noAddress, 'ZS12345', 'x', false, [], $user);
    }

    /** Adres bez miejscowości/współrzędnych (reverse geocode jeszcze nie wrócił) nie może przejść – web też tego nie puszcza. */
    public function testUpdateRejectsAddressWithoutCityOrCoordinates(): void
    {
        $user = $this->savedUser();
        $app = $this->appWithImages($user);
        $make = function (array $over) {
            $a = new \JSONObject();
            foreach (['address' => 'Mazurska 37, Szczecin', 'city' => 'Szczecin', 'voivodeship' => 'zachodniopomorskie', 'lat' => 53.43, 'lng' => 14.55] as $k => $v)
                $a->$k = array_key_exists($k, $over) ? $over[$k] : $v;
            return $a;
        };
        foreach ([['city' => null], ['lat' => null], ['lng' => 0], ['address' => 'Krótki 1']] as $over) {
            try {
                updateApplication($app, '2026-09-10T19:43:00', true, 8, $make($over), 'ZS12345', 'x', false, [], $user);
                $this->fail('expected ValidationException for ' . json_encode($over));
            } catch (\ValidationException $e) {
                $this->assertSame('address', $e->getField());
            }
        }
    }

    public function testUpdateRejectsOldDateButKeepsUnchangedOne(): void
    {
        $user = $this->savedUser();
        $app = $this->appWithImages($user);
        $address = new \JSONObject();
        $address->address = 'Mazurska 37, Szczecin';
        $address->city = 'Szczecin';
        $address->lat = 53.43;
        $address->lng = 14.55;
        $address->voivodeship = 'zachodniopomorskie';
        $old = (new \DateTime())->modify('-8 months')->format('Y-m-d\TH:i:s');

        try {
            updateApplication($app, $old, true, 8, $address, 'ZS12345', 'x', false, [], $user);
            $this->fail('a date older than 7 months must be rejected');
        } catch (\ValidationException $e) {
            $this->assertSame('datetime', $e->getField());
        }

        // edycja starego zgłoszenia: ta sama data zapisana ponownie jest dozwolona
        $app->date = (new \DateTime($old))->format(DT_FORMAT);
        $saved = updateApplication($app, $old, true, 8, $address, 'ZS12345', 'x', false, [], $user);
        $this->assertSame($app->date, $saved->date);
    }

    public function testFinishRequiresSavedReportAndOwnership(): void
    {
        $user = $this->savedUser();
        $app = $this->appWithImages($user); // still `draft`

        try {
            finishApplication($app->id, $user);
            $this->fail('draft must not be finishable');
        } catch (\ValidationException $e) {
            $this->assertSame('status', $e->getField());
        }

        $other = $this->savedUser('someone-else@example.com');
        $this->expectExceptionCode(403);
        finishApplication($app->id, $other);
    }

    public function testFinishConfirmsAssignsNumberAndIsIdempotent(): void
    {
        $user = $this->savedUser();
        $app = $this->appWithImages($user);
        $app->carInfo = new \stdClass();
        $app->carInfo->plateId = 'ZS12345';
        $app->date = '2026-09-10T19:43:00';
        $app->address->address = 'Mazurska 37, Szczecin';
        $app->address->lat = 53.43;
        $app->address->lng = 14.55;
        $app->setStatus('ready');
        \app\save($app);

        $first = finishApplication($app->id, $user);
        $this->assertTrue($first['changed']);
        $this->assertSame('confirmed', $first['application']->status);
        $this->assertNotEmpty($first['application']->number);
        $this->assertGreaterThan(0, $first['appsCount']);

        $second = finishApplication($app->id, $user); // confirmed is still editable → re-finish is an "edit"
        $this->assertSame('confirmed', $second['application']->status);
    }

    public function testApplicationToRestAddsRecipientAndDropsBrowser(): void
    {
        $user = $this->savedUser();
        $app = $this->appWithImages($user);
        $app->browser = 'phpunit';

        $data = applicationToRest($app);
        $this->assertArrayNotHasKey('browser', $data);
        $this->assertIsArray($data['recipient']);
        foreach (['name', 'shortName', 'isPolice', 'automated', 'unknown', 'stopAgresjiForced'] as $key) {
            $this->assertArrayHasKey($key, $data['recipient']);
        }
        $this->assertTrue($data['recipient']['isPolice']); // a fresh user defaults to Policja (see ApplicationTest::testWithUser)
    }

    public function testConfirmationDataMatchesTheWebConfirmationPage(): void
    {
        $user = $this->savedUser();
        $user->data->name = 'Anna Kowalska';
        \user\save($user);
        $app = $this->appWithImages($user);
        $app->user->sex = 'f'; // feminine phrases
        $app->category = 26;
        $app->userComment = 'Stoi od rana.';
        $app->carInfo = new \stdClass();
        $app->carInfo->plateId = 'ZS12345';
        $app->address->address = 'Mazurska 37, Szczecin';
        $app->address->city = 'Szczecin';

        $c = confirmationData($app);
        $this->assertStringStartsWith('Pojazd był zaparkowany częściowo na drodze dla pieszych', $c['body']);
        $this->assertStringEndsWith('Stoi od rana.', $c['body']);
        $this->assertSame('Nie byłaś świadkiem parkowania.', $c['witness']);
        $this->assertSame('ZS12345', $c['plateId']);
        $this->assertSame('Anna Kowalska', $c['sender']['name']);
        $this->assertArrayHasKey('automated', $c['recipient']);

        $app->statements->witness = true;
        $this->assertSame('Byłaś świadkiem parkowania.', confirmationData($app)['witness']);
        $app->user->sex = 'm';
        $this->assertSame('Byłeś świadkiem parkowania.', confirmationData($app)['witness']);
    }
}
