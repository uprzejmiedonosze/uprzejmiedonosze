<?php

namespace UprzejmieDonosze\Tests;

use app\Application;
use user\User;

// API.php (free functions setStatus/sendApplication) is not pulled in by
// include.php, so load it explicitly.
require_once(__DIR__ . '/../export/inc/API.php');

/**
 * Access-control behaviour of the legacy session-API operations in
 * src/inc/API.php. A user must never be able to mutate or send a report that
 * belongs to someone else.
 */
class ApiTest extends DatabaseTestCase
{
    private $appJson = '{"date":"2019-03-31T13:06:23","id":"66610107-29dd-4392-8bae-83c71426d844","added":"2019-04-14T13:22:48","user":{"email":"e@nieradka.net","name":"Ud Developer","number":2,"exposeData":false,"msisdn":"","address":"Rynek 99-120, Piątek"},"status":"confirmed","category":8,"statements":{"witness":false},"statusHistory":{"2019-04-14T13:27:05":{"old":"draft","new":"ready"},"2019-04-14T13:27:11":{"old":"ready","new":"confirmed"}},"contextImage":{"url":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811.jpg","thumb":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811,t.jpg"},"carImage":{"url":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12.jpg","thumb":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12,t.jpg"},"carInfo":{"plateId":"ZS2450C","plateIdFromImage":"ZS2450C","brand":"Audi","plateImage":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12,p.jpg","recydywa":0},"dtFromPicture":true,"address":{"address":"aleja Papieża Jana Pawła II 36, Szczecin","city":"Szczecin","voivodeship":"zachodniopomorskie","lat":53.43474358333333,"lng":14.545931694444445},"smCity":"szczecin","userComment":"","number":"UD\/2\/2","comments":[],"extensions":[],"seq":2,"inexactHour":true,"version":"2.3.0"}';

    /**
     * Build a User whose getEmail() returns $email (User reads it from session).
     */
    private function makeUser(string $email): User
    {
        $_SESSION['user_email'] = $email;
        $_SESSION['user_id'] = crc32($email);
        return new User();
    }

    /**
     * Persist a fresh 'confirmed' report owned by $ownerEmail under id $id.
     */
    private function seedApp(string $ownerEmail, string $id): void
    {
        $this->makeUser($ownerEmail); // owner session for encryption
        $app = Application::withJson($this->appJson, $ownerEmail);
        $app->id = $id;
        \app\save($app);
    }

    // ── changing a report's status ────────────────────────────────────────────

    public function testSetStatusRejectsNonOwner(): void
    {
        $id = 'apitest-status-reject';
        $this->seedApp('owner-status@example.com', $id);
        $attacker = $this->makeUser('attacker-status@example.com');

        try {
            \setStatus('confirmed-waiting', $id, $attacker);
            $this->fail('Non-owner was allowed to change report status');
        } catch (\ForbiddenException $e) {
            $this->assertStringContainsString($id, $e->getMessage());
        }

        // The report must NOT have been mutated before the ownership check.
        $this->assertEquals('confirmed', \app\get($id)->status);
    }

    public function testSetStatusAllowsOwner(): void
    {
        $id = 'apitest-status-ok';
        $ownerEmail = 'owner-status-ok@example.com';
        $this->seedApp($ownerEmail, $id);
        $owner = $this->makeUser($ownerEmail);

        try {
            \setStatus('confirmed-waiting', $id, $owner);
        } catch (\ForbiddenException $e) {
            $this->fail('Owner was incorrectly denied: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // The ownership gate + status write happen inside the lock; the
            // post-lock badge stats reach Patronite over the network, which is
            // unavailable in the test env (same as the skipped S3 paths).
        }

        // Owner passed the gate and the status was actually persisted.
        $this->assertEquals('confirmed-waiting', \app\get($id)->status);
    }

    // ── sending a report to the authorities ───────────────────────────────────

    public function testSendApplicationRejectsNonOwner(): void
    {
        $id = 'apitest-send-reject';
        $this->seedApp('owner-send@example.com', $id);
        $attacker = $this->makeUser('attacker-send@example.com');

        // Ownership is checked before CityAPI::checkApplication and any network
        // send, so a non-owner is rejected without side effects.
        $this->expectException(\ForbiddenException::class);
        \sendApplication($id, $attacker);
    }
}
