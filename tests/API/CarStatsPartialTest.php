<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/handlers/StaticPagesHandler.php';

use app\Application;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use user\User;
use UprzejmieDonosze\Tests\DatabaseTestCase;

class CarStatsPartialTest extends DatabaseTestCase
{
    private const APP_JSON_TEMPLATE = '{"date":"2019-03-31T13:06:23","id":"66610107-29dd-4392-8bae-83c71426d844","added":"2019-04-14T13:22:48","user":{"email":"test@example.com","name":"Test User","number":2,"exposeData":false,"msisdn":"","address":"Rynek 99-120, Piątek"},"status":"confirmed-fined","category":8,"statements":{"witness":false,"gallery":false},"statusHistory":[],"contextImage":{"url":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811.jpg","thumb":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811,t.jpg","galleryReady":true},"carImage":{"url":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12.jpg","thumb":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12,t.jpg"},"carInfo":{"plateId":"ZS2450C","brand":"Audi","recydywa":0},"dtFromPicture":true,"address":{"address":"aleja Papieża Jana Pawła II 36, Szczecin","city":"Szczecin","voivodeship":"zachodniopomorskie","lat":53.43474358333333,"lng":14.545931694444445},"smCity":"szczecin","userComment":"","number":"UD\/2\/2","comments":[],"extensions":[],"seq":2,"inexactHour":true,"version":"2.3.0"}';

    private function createTestUser(string $email): User
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['user_email'] = $email;
        $user = new User();
        $user->email = $email;
        $user->name = 'Test User';
        $user->data->shareRecydywa = true;
        \user\save($user);
        return $user;
    }

    private function createTestApp(string $email, string $plateId, string $status = 'confirmed-fined'): Application
    {
        $this->createTestUser($email);

        $data = json_decode(self::APP_JSON_TEMPLATE, true);
        $data['id'] = bin2hex(random_bytes(16));
        $data['user']['email'] = $email;
        $data['status'] = $status;
        $data['carInfo']['plateId'] = $plateId;
        $app = Application::withJson(json_encode($data), $email);
        $app->initStatements();
        \app\save($app);

        return $app;
    }

    private function createRequest(string $url, ?User $user = null)
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $url)
            ->withAttribute('parameters', [])
            ->withAttribute('view', initSlimTwig());

        if ($user) {
            $request = $request->withAttribute('user', $user);
        }

        return $request;
    }

    public function testCarStatsPartialAllowsSingleReportWhenReportedByMe(): void
    {
        $email = 'me@example.com';
        $user = $this->createTestUser($email);
        $_SESSION['user_email'] = $email;
        $_SESSION['user_id'] = 1;

        $plate = 'WA99901';
        $this->createTestApp($email, $plate);

        $handler = new \StaticPagesHandler();
        $request = $this->createRequest("http://localhost/app/recydywa/partial/{$plate}", $user);

        $response = $handler->carStatsPartial($request, new Response(), ['plateId' => $plate]);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        $this->assertStringContainsString($plate, $body);
    }

    public function testCarStatsPartialThrows404WhenSingleReportReportedBySomeoneElse(): void
    {
        $myEmail = 'me@example.com';
        $myUser = $this->createTestUser($myEmail);
        $_SESSION['user_email'] = $myEmail;
        $_SESSION['user_id'] = 1;

        $otherEmail = 'other@example.com';
        $plate = 'WA99902';
        $this->createTestApp($otherEmail, $plate);

        $_SESSION['user_email'] = $myEmail;
        $_SESSION['user_id'] = 1;

        $handler = new \StaticPagesHandler();
        $request = $this->createRequest("http://localhost/app/recydywa/partial/{$plate}", $myUser);

        $this->expectException(HttpNotFoundException::class);
        $handler->carStatsPartial($request, new Response(), ['plateId' => $plate]);
    }

    public function testCarStatsPartialThrows404WhenMultipleReportsOnlyBySomeoneElse(): void
    {
        $myEmail = 'me@example.com';
        $myUser = $this->createTestUser($myEmail);
        $_SESSION['user_email'] = $myEmail;
        $_SESSION['user_id'] = 1;

        $otherEmail = 'other@example.com';
        $plate = 'WA99903';
        $this->createTestApp($otherEmail, $plate);
        $this->createTestApp($otherEmail, $plate);

        $_SESSION['user_email'] = $myEmail;
        $_SESSION['user_id'] = 1;

        $handler = new \StaticPagesHandler();
        $request = $this->createRequest("http://localhost/app/recydywa/partial/{$plate}", $myUser);

        $this->expectException(HttpNotFoundException::class);
        $handler->carStatsPartial($request, new Response(), ['plateId' => $plate]);
    }

    public function testCarStatsPartialAllowsWhenReportedByMultipleUsers(): void
    {
        $myEmail = 'me@example.com';
        $myUser = $this->createTestUser($myEmail);
        $_SESSION['user_email'] = $myEmail;
        $_SESSION['user_id'] = 1;

        $user1 = 'user1@example.com';
        $user2 = 'user2@example.com';
        $plate = 'WA99904';
        $this->createTestApp($user1, $plate);
        $this->createTestApp($user2, $plate);

        $_SESSION['user_email'] = $myEmail;
        $_SESSION['user_id'] = 1;

        $handler = new \StaticPagesHandler();
        $request = $this->createRequest("http://localhost/app/recydywa/partial/{$plate}", $myUser);

        $response = $handler->carStatsPartial($request, new Response(), ['plateId' => $plate]);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();
        $this->assertStringContainsString($plate, $body);
    }

    public function testCarStatsFullThrows404WhenSingleReportReportedByMe(): void
    {
        $email = 'me@example.com';
        $user = $this->createTestUser($email);
        $_SESSION['user_email'] = $email;
        $_SESSION['user_id'] = 1;

        $plate = 'WA99905';
        $this->createTestApp($email, $plate);

        $handler = new \StaticPagesHandler();
        $request = $this->createRequest("http://localhost/tablica-rejestracyjna-{$plate}.html", $user);

        $this->expectException(HttpNotFoundException::class);
        $handler->carStatsFull($request, new Response(), ['plateId' => $plate]);
    }
}
