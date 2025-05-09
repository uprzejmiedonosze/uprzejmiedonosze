<?php

namespace UprzejmieDonosze\Tests\Store;

require_once __DIR__ . '/../../export/inc/store/index.php';
require_once __DIR__ . '/../../export/inc/integrations/Tumblr.php';

use app\Application;
use PHPUnit\Framework\TestCase;
use user\User;

class RecydywaStoreTest extends TestCase
{
    private function getApp(): Application
    {
        $appJson = '{"date":"2019-03-31T13:06:23","id":"66610107-29dd-4392-8bae-83c71426d844","added":"2019-04-14T13:22:48","user":{"email":"e@nieradka.net","name":"Ud Developer","exposeData":false,"msisdn":"","address":"Rynek 99-120, Pi\u0105tek"},"status":"confirmed","category":8,"statusHistory":{"2019-04-14T13:27:05":{"old":"draft","new":"ready"},"2019-04-14T13:27:11":{"old":"ready","new":"confirmed"}},"contextImage":{"url":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811.jpg","thumb":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811,t.jpg"},"carImage":{"url":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12.jpg","thumb":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12,t.jpg"},"carInfo":{"plateId":"ZS2450C","plateIdFromImage":"ZS2450C","brand":"Audi","plateImage":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12,p.jpg","recydywa":0},"dtFromPicture":true,"address":{"address":"aleja Papie\u017ca Jana Paw\u0142a II 36, Szczecin","city":"Szczecin","voivodeship":"zachodniopomorskie","lat":53.43474358333333,"lng":14.545931694444445},"smCity":"szczecin","userComment":"","number":"UD\/2\/2","comments":[],"extensions":[],"seq":2,"inexactHour":true,"version":"2.3.0"}';
        $email = 'e@nieradka.net';
        $_SESSION['user_email'] = $email;
        $app = Application::withJson($appJson, $email);
        $app->initStatements();
        $app->setStatus('sending');
        $app->setStatus('confirmed-waiting');
        $app->setStatus('confirmed-fined');

        $user = new User();
        $user->email = $email;
        $user->name = 'Ud Developer';
        $user->exposeData = false;
        $user->msisdn = '';
        $user->data->shareRecydywa = true;
        \user\save($user);

        $app->updateUserData($user);
        return $app;

    }

    public function testGet(): void
    {
        $app = $this->getApp();
        \app\save($app);
        $recydywa = \recydywa\get($app->carInfo->plateId, false);
        self::assertInstanceOf(\recydywa\Recydywa::class, $recydywa);
        self::assertEquals(1, $recydywa->appsCnt);
        self::assertEquals(1, $recydywa->usersCnt);
    }
    public function testDelete(): void
    {
        $app = $this->getApp();
        \app\save($app);
        \recydywa\delete($app->carInfo->plateId);
        $recydywa = \recydywa\get($app->carInfo->plateId, false);
        self::assertInstanceOf(\recydywa\Recydywa::class, $recydywa);
        self::assertEquals(1, $recydywa->appsCnt);
        self::assertEquals(1, $recydywa->usersCnt);
    }
    public function testGetDetailed(): void
    {
        $app = $this->getApp();
        $_SESSION['user_id'] = 1;
        \app\save($app);
        $detailed = \recydywa\getDetailed($app->carInfo->plateId, false);
        self::assertIsObject($detailed);
        self::assertIsArray($detailed->apps);
        self::assertEquals(1, count($detailed->apps));
    }

    public function testAddToGallery(): void
    {
        $app = $this->getApp();
        self::assertObjectHasProperty('statements', $app);
        self::assertObjectHasProperty('gallery', $app->statements);
        //$app = addToGallery($app);
        //self::assertEquals(1, sizeof($app->comments));
        //self::assertObjectHasProperty('addedToGallery', $app);
    }
}
