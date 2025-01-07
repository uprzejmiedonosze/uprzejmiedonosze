<?php

namespace UprzejmieDonosze\Tests\Dataclasses;

use PHPUnit\Framework\TestCase;
use app\Application;
use Error;
use user\User;

class ApplicationTest extends TestCase
{
    private $appJson = '{"date":"2019-03-31T13:06:23","id":"66610107-29dd-4392-8bae-83c71426d844","added":"2019-04-14T13:22:48","user":{"email":"e@nieradka.net","name":"Ud Developer","exposeData":false,"msisdn":"","address":"Rynek 99-120, Pi\u0105tek"},"status":"confirmed","category":8,"statements":{"witness":false},"statusHistory":{"2019-04-14T13:27:05":{"old":"draft","new":"ready"},"2019-04-14T13:27:11":{"old":"ready","new":"confirmed"}},"contextImage":{"url":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811.jpg","thumb":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811,t.jpg"},"carImage":{"url":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12.jpg","thumb":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12,t.jpg"},"carInfo":{"plateId":"ZS2450C","plateIdFromImage":"ZS2450C","brand":"Audi","plateImage":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12,p.jpg","recydywa":0},"dtFromPicture":true,"address":{"address":"aleja Papie\u017ca Jana Paw\u0142a II 36, Szczecin","city":"Szczecin","voivodeship":"zachodniopomorskie","lat":53.43474358333333,"lng":14.545931694444445},"smCity":"szczecin","userComment":"","number":"UD\/2\/2","comments":[],"extensions":[],"seq":2,"inexactHour":true,"version":"2.3.0"}';

    private function assertThrowsException(\Closure $closure, string $exception, string $message = null)
    {
        try {
            $closure();
            $this->fail("Closure doesn't throw any exception");
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->assertInstanceOf($exception, $e);
            $this->assertEquals($e->getMessage(), $message);
        }
    }

    public function testPrivateConstructor()
    {
        $this->expectException(Error::class);
        new Application();
    }

    public function testWithJson()
    {
        $app = Application::withJson($this->appJson);
        $this->assertEquals('2019-03-31T13:06:23', $app->date);
        $this->assertEquals('66610107-29dd-4392-8bae-83c71426d844', $app->id);
        $this->assertEquals('2019-04-14T13:22:48', $app->added);
        $this->assertEquals('m', $app->user->sex);
    }

    public function testWithUser()
    {
        $app = Application::withUser(new User());
        $this->assertTrue($app->user->shareRecydywa);
        $this->assertFalse($app->stopAgresji);
        $this->assertEquals(0, $app->category);
        $this->assertEquals(12, strlen($app->id));
    }

    public function testUpdateUserData()
    {
        $user = new User();
        $app = Application::withUser($user);
        $user->data->name = 'Ud Developer';
        $app->updateUserData($user);
        $this->assertEquals($user->data->name, $app->user->name);
    }

    public function testWasSent()
    {
        $app = Application::withJson($this->appJson);
        $this->assertFalse($app->wasSent());

        $app->setStatus('ready');
        $this->assertFalse($app->wasSent());

        $app->setStatus('confirmed');
        $this->assertFalse($app->wasSent());
    }

    public function testSetStatus()
    {
        $app = Application::withJson($this->appJson);
        $this->assertEquals('confirmed', $app->status);
        $this->assertEquals(2, sizeof($app->statusHistory));

        $app = Application::withUser(new User());
        $this->assertEquals('draft', $app->status);
        $this->assertNull($app->statusHistory ?? null);

        $app->setStatus('ready');
        $this->assertEquals(1, sizeof($app->statusHistory));
        $this->assertTrue($app->isEditable());

        $this->assertThrowsException(function() use ($app) {
            $app->setStatus('submitted');
        }, \Exception::class, "Odmawiam ustawienia statusu na 'submitted'");
        $this->assertEquals(1, sizeof($app->statusHistory));

        $this->assertThrowsException(function() use ($app) {
            $app->setStatus('sending');
        }, \Exception::class, "Odmawiam zmiany statusu z 'ready' na 'sending' dla zgłoszenia '{$app->id}'");
        $this->assertEquals(1, sizeof($app->statusHistory));
    }

    public function testIsEditableSendable()
    {
        $app = Application::withUser(new User());
        $this->assertTrue($app->isEditable());
        $this->assertFalse($app->getStatus()->sendable);

        $app->setStatus('ready');
        $this->assertTrue($app->isEditable());
        $this->assertFalse($app->getStatus()->sendable);

        $app->setStatus('confirmed');
        $this->assertTrue($app->isEditable());
        $this->assertTrue($app->getStatus()->sendable);
        
        $app->setStatus('confirmed-waiting');
        $this->assertFalse($app->isEditable());
        $this->assertFalse($app->getStatus()->sendable);
    }

    public function testgetUserNumber()
    {
        $app = Application::withJson($this->appJson);
        $this->assertEquals('2', $app->getUserNumber());
    }

    public function testHasNumber()
    {
        $app = Application::withJson($this->appJson);
        $this->assertTrue($app->hasNumber());

        $app = Application::withUser(new User());
        $this->assertFalse($app->hasNumber());
    }

    public function testAddComment()
    {
        $app = Application::withJson($this->appJson);
        $this->assertEmpty($app->comments);

        $app->addComment('admin', 'test');
        $this->assertEquals(1, sizeof($app->comments));
        $this->assertEquals('test', reset($app->comments)->comment);
    }

    public function testGetRevision()
    {
        $app = Application::withUser(new User());
        $this->assertEquals(0, $app->getRevision());

        $app->setStatus('ready');
        $this->assertEquals(1, $app->getRevision());
    }

    public function testIsAppOwner()
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('test@example.com');

        $app = Application::withUser(new User());
        $app->user = (object)['email' => 'test@example.com'];

        $this->assertTrue($app->isAppOwner($user));

        $user->method('getEmail')->willReturn('other@example.com');

        $this->assertFalse($app->isAppOwner(null));
    }

    public function testEncode()
    {
        $app = Application::withJson($this->appJson);
        $_SESSION['user_id'] = 1;

        $encoded = $app->encode();
        $decoded = Application::withJson($encoded);
        $encoded = json_decode($encoded);

        $this->assertIsString($encoded->user);
        $this->assertEquals($app->user->name, $decoded->user->name);
    }

    public function testEncodeNoSession()
    {
        $app = Application::withJson($this->appJson);
        $_SESSION['user_id'] = 1;

        $encoded = $app->encode();

        $_SESSION['user_id'] = null;
        $decoded = Application::withJson($encoded);
        $this->assertIsString($decoded->user);
        $this->assertIsString($decoded->address);
        $this->assertIsString($decoded->encrypted);
    }

    public function testEncodeOtherSession()
    {
        $app = Application::withJson($this->appJson);
        $_SESSION['user_id'] = 1;

        $encoded = $app->encode();

        $_SESSION['user_id'] = 2;
        $decoded = Application::withJson($encoded);
        $this->assertIsString($decoded->user);
        $this->assertIsString($decoded->address);
        $this->assertIsString($decoded->encrypted);
    }
}