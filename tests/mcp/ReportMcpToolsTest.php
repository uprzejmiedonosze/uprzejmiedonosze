<?php

namespace UprzejmieDonosze\Tests\Mcp;

require_once __DIR__ . '/../../export/inc/mcp/McpIdentity.php';
require_once __DIR__ . '/../../export/inc/mcp/ReportMcpTools.php';
require_once __DIR__ . '/../../export/inc/API.php';

use app\Application;
use mcp\McpIdentity;
use mcp\ReportMcpTools;
use mcp\ReportStatus;
use RuntimeException;
use user\User;
use UprzejmieDonosze\Tests\DatabaseTestCase;

class ReportMcpToolsTest extends DatabaseTestCase
{
    // Known-good fixture (same one used by RecydywaStoreTest/ApplicationTest) — id/status/email
    // are overwritten per test via json_decode/encode, everything else is left as-is.
    private const APP_JSON_TEMPLATE = '{"date":"2019-03-31T13:06:23","id":"66610107-29dd-4392-8bae-83c71426d844","added":"2019-04-14T13:22:48","user":{"email":"e@nieradka.net","name":"Ud Developer","number":2,"exposeData":false,"msisdn":"","address":"Rynek 99-120, Piątek"},"status":"confirmed","category":8,"statements":{"witness":false},"statusHistory":{"2019-04-14T13:27:05":{"old":"draft","new":"ready"},"2019-04-14T13:27:11":{"old":"ready","new":"confirmed"}},"contextImage":{"url":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811.jpg","thumb":"cdn\/ce883f8d-2f8d-4048-8725-76a2777b2811,t.jpg"},"carImage":{"url":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12.jpg","thumb":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12,t.jpg"},"carInfo":{"plateId":"ZS2450C","plateIdFromImage":"ZS2450C","brand":"Audi","plateImage":"cdn\/d74a29f5-9cde-4370-a8f0-fcc1dc9bcd12,p.jpg","recydywa":0},"dtFromPicture":true,"address":{"address":"aleja Papieża Jana Pawła II 36, Szczecin","city":"Szczecin","voivodeship":"zachodniopomorskie","lat":53.43474358333333,"lng":14.545931694444445},"smCity":"szczecin","userComment":"","number":"UD\/2\/2","comments":[],"extensions":[],"seq":2,"inexactHour":true,"version":"2.3.0"}';

    protected function setUp(): void
    {
        parent::setUp();
        // Deliberately never set $_SESSION['user_id'] in this test: it would make
        // Application::encode() encrypt the fixture, and decode() only succeeds
        // again with the exact same uid — an irrelevant complication for testing
        // ReportMcpTools' own scope/ownership logic in isolation.
        McpIdentity::setScopes([]);
    }

    private function makeApp(string $id, string $status, string $email): Application
    {
        $data = json_decode(self::APP_JSON_TEMPLATE, true);
        $data['id'] = $id;
        $data['status'] = $status;
        $app = Application::withJson(json_encode($data), $email);
        $app->initStatements();
        \app\save($app);
        return $app;
    }

    private function actAs(string $email, array $scopes): User
    {
        $user = new User();
        $user->data->email = $email;
        McpIdentity::set($user);
        McpIdentity::setScopes($scopes);
        return $user;
    }

    public function testListReportsRequiresReadScope(): void
    {
        $this->actAs('a@b.com', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires the 'reports:read' OAuth scope");
        (new ReportMcpTools())->listReports();
    }

    public function testListReportsExcludesDraftsByDefaultAndOnlyOwnReports(): void
    {
        $this->makeApp('mcp-list-own1', 'confirmed-waiting', 'owner@example.com');
        $this->makeApp('mcp-list-own2', 'draft', 'owner@example.com');
        $this->makeApp('mcp-list-other', 'confirmed-waiting', 'someone-else@example.com');

        $this->actAs('owner@example.com', ['reports:read']);

        $result = (new ReportMcpTools())->listReports();

        self::assertArrayHasKey('reports', $result);
        $ids = array_column($result['reports'], 'id');
        self::assertContains('mcp-list-own1', $ids);
        self::assertNotContains('mcp-list-own2', $ids, 'draft reports must be excluded from the default "all" filter');
        self::assertNotContains('mcp-list-other', $ids, 'must never see another user\'s reports');
    }

    public function testListReportsAllWithDraftsIncludesDrafts(): void
    {
        $this->makeApp('mcp-list-draft', 'draft', 'owner2@example.com');

        $this->actAs('owner2@example.com', ['reports:read']);

        $result = (new ReportMcpTools())->listReports(status: 'allWithDrafts');

        self::assertContains('mcp-list-draft', array_column($result['reports'], 'id'));
    }

    public function testGetReportRequiresReadScope(): void
    {
        $app = $this->makeApp('mcp-get-scope', 'confirmed-waiting', 'a@b.com');
        $this->actAs('a@b.com', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires the 'reports:read' OAuth scope");
        (new ReportMcpTools())->getReport($app->id);
    }

    public function testGetReportReturnsOwnReport(): void
    {
        $app = $this->makeApp('mcp-get-own', 'confirmed-waiting', 'owner3@example.com');
        $this->actAs('owner3@example.com', ['reports:read']);

        $result = (new ReportMcpTools())->getReport($app->id);

        self::assertSame('mcp-get-own', $result['id']);
        self::assertSame('confirmed-waiting', $result['status']);
    }

    public function testGetReportExpandsCategoryAndOmitsStatusInfo(): void
    {
        // The fixture uses category 8. get_report should expand it into
        // categoryInfo; status semantics live in the server instructions, so
        // no per-report statusInfo object is added.
        $app = $this->makeApp('mcp-get-cat', 'confirmed-waiting', 'owner-cat@example.com');
        $this->actAs('owner-cat@example.com', ['reports:read']);

        $result = (new ReportMcpTools())->getReport($app->id);

        self::assertArrayHasKey('categoryInfo', $result);
        self::assertSame(8, $result['categoryInfo']['id']);
        self::assertNotEmpty($result['categoryInfo']['title']);
        self::assertArrayHasKey('law', $result['categoryInfo']);
        // English-friendly names for the Polish "mandat" (fine) and "punkty karne".
        self::assertArrayHasKey('fine', $result['categoryInfo']);
        self::assertArrayHasKey('demeritPoints', $result['categoryInfo']);
        self::assertArrayNotHasKey('mandate', $result['categoryInfo']);
        self::assertArrayNotHasKey('statusInfo', $result);
        self::assertSame(8, $result['category'], 'raw category number kept');
    }

    public function testListReportsExpandCategoryInfo(): void
    {
        $this->makeApp('mcp-list-cat', 'confirmed-waiting', 'owner-lc@example.com');
        $this->actAs('owner-lc@example.com', ['reports:read']);

        $result = (new ReportMcpTools())->listReports();

        self::assertNotEmpty($result['reports']);
        self::assertArrayHasKey('categoryInfo', $result['reports'][0]);
        self::assertArrayNotHasKey('statusInfo', $result['reports'][0]);
    }

    public function testGetReportExpandsRecipientInfo(): void
    {
        // The fixture's smCity "szczecin" (keys are lower-cased on load) resolves
        // to the real Straż Miejska w Szczecinie unit; the report isn't
        // stopAgresji, so it routes to the city guard (not police).
        $this->makeApp('mcp-recipient', 'confirmed-waiting', 'owner-rec@example.com');
        $this->actAs('owner-rec@example.com', ['reports:read']);

        $result = (new ReportMcpTools())->getReport('mcp-recipient');

        self::assertArrayHasKey('recipientInfo', $result);
        self::assertStringContainsString('Szczecin', $result['recipientInfo']['name']);
        self::assertSame('zgloszenia@sm.szczecin.pl', $result['recipientInfo']['email']);
        self::assertFalse($result['recipientInfo']['isPolice']);
        self::assertIsArray($result['recipientInfo']['address']);
    }

    public function testGetReportRejectsAnotherUsersReport(): void
    {
        $app = $this->makeApp('mcp-get-other', 'confirmed-waiting', 'victim@example.com');
        $this->actAs('attacker@example.com', ['reports:read']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Report 'mcp-get-other' not found");
        (new ReportMcpTools())->getReport($app->id);
    }

    public function testUpdateReportStatusRequiresWriteScope(): void
    {
        $app = $this->makeApp('mcp-update-scope', 'confirmed-waiting', 'a@b.com');
        // reports:read alone must not be enough to write.
        $this->actAs('a@b.com', ['reports:read']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires the 'reports:status:write' OAuth scope");
        (new ReportMcpTools())->updateReportStatus($app->id, ReportStatus::Archived);
    }

    public function testUpdateReportStatusUpdatesOwnReport(): void
    {
        $app = $this->makeApp('mcp-update-own', 'confirmed-waiting', 'owner4@example.com');
        $this->actAs('owner4@example.com', ['reports:status:write']);

        $result = (new ReportMcpTools())->updateReportStatus($app->id, ReportStatus::ConfirmedFined);

        self::assertSame('confirmed-fined', $result['status']);
        self::assertSame('confirmed-fined', \app\get('mcp-update-own')->status);
    }

    public function testUpdateReportStatusRejectsAnotherUsersReport(): void
    {
        $app = $this->makeApp('mcp-update-other', 'confirmed-waiting', 'victim2@example.com');
        $this->actAs('attacker2@example.com', ['reports:status:write']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Report 'mcp-update-other' not found");
        (new ReportMcpTools())->updateReportStatus($app->id, ReportStatus::Archived);

        self::assertSame('confirmed-waiting', \app\get('mcp-update-other')->status, 'the report must be untouched');
    }

    public function testUpdateReportStatusRejectsDisallowedTransition(): void
    {
        // 'draft' only allows moving to 'ready' — none of the recordable
        // outcomes are reachable from it, so the domain layer must reject this.
        $app = $this->makeApp('mcp-update-invalid', 'draft', 'owner5@example.com');
        $this->actAs('owner5@example.com', ['reports:status:write']);

        $this->expectException(RuntimeException::class);
        (new ReportMcpTools())->updateReportStatus($app->id, ReportStatus::Archived);
    }
}
