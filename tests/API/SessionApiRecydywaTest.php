<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/handlers/SessionApiHandler.php';

use app\Application;
use Exception;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use user\User;
use UprzejmieDonosze\Tests\DatabaseTestCase;

class SessionApiRecydywaTest extends DatabaseTestCase
{
    private const APP_JSON_TEMPLATE = '{"date":"2019-03-31T13:06:23","id":"66610107-29dd-4392-8bae-83c71426d844","added":"2019-04-14T13:22:48","user":{"email":"test@example.com","name":"Test User","number":2,"exposeData":false,"msisdn":"","address":"Rynek 99-120, Piątek"},"status":"draft","category":8,"statements":{"witness":false},"statusHistory":[],"contextImage":{"url":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811.jpg","thumb":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811,t.jpg"},"carImage":{"url":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12.jpg","thumb":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12,t.jpg"},"carInfo":{"plateId":"ZS2450C","brand":"Audi","recydywa":0},"dtFromPicture":true,"address":{"address":"aleja Papieża Jana Pawła II 36, Szczecin","city":"Szczecin","voivodeship":"zachodniopomorskie","lat":53.43474358333333,"lng":14.545931694444445},"smCity":"szczecin","userComment":"","number":"UD\/2\/2","comments":[],"extensions":[],"seq":2,"inexactHour":true,"version":"2.3.0"}';

    private function createTestApp(string $email, ?string $plateId): Application
    {
        $_SESSION['user_email'] = $email;
        $_SESSION['user_id'] = 1;

        $user = new User();
        $user->email = $email;
        $user->name = 'Test User';
        $user->data->shareRecydywa = true;
        \user\save($user);

        $data = json_decode(self::APP_JSON_TEMPLATE, true);
        $data['user']['email'] = $email;
        if ($plateId !== null) {
            $data['carInfo']['plateId'] = $plateId;
        } else {
            unset($data['carInfo']['plateId']);
        }
        $app = Application::withJson(json_encode($data), $email);
        $app->initStatements();
        \app\save($app);

        return $app;
    }

    public function testRecydywaUsesApplicationPlateIdByDefault(): void
    {
        $email = 'test@example.com';
        $app = $this->createTestApp($email, 'ZS2450C');

        $handler = new \SessionApiHandler();
        $request = (new ServerRequestFactory())->createServerRequest('GET', "http://localhost/api/app/{$app->id}/recydywa");

        $response = $handler->recydywa($request, new Response(), ['appId' => $app->id]);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame('ZS2450C', $body['plateId']);
    }

    public function testRecydywaUsesQueryParamPlateId(): void
    {
        $email = 'test@example.com';
        $app = $this->createTestApp($email, 'ZS2450C');

        $handler = new \SessionApiHandler();
        $request = (new ServerRequestFactory())->createServerRequest('GET', "http://localhost/api/app/{$app->id}/recydywa?plateId=WA12345")
            ->withQueryParams(['plateId' => 'WA12345']);

        $response = $handler->recydywa($request, new Response(), ['appId' => $app->id]);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame('WA12345', $body['plateId']);
        $this->assertIsArray($body['apps']);
    }

    public function testRecydywaThrows404WhenNoPlateOnAppAndNoQueryParam(): void
    {
        $email = 'test@example.com';
        $app = $this->createTestApp($email, null);

        $handler = new \SessionApiHandler();
        $request = (new ServerRequestFactory())->createServerRequest('GET', "http://localhost/api/app/{$app->id}/recydywa");

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Brak numeru rejestracyjnego dla zgłoszenia {$app->id}");

        $handler->recydywa($request, new Response(), ['appId' => $app->id]);
    }
}
