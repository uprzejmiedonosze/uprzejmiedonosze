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

    public function testGetReportRejectsUnknownReport(): void
    {
        // \app\get throws for an unknown id; the tool must still surface a clean
        // "not found" (ToolCallException extends RuntimeException), not an opaque
        // internal error.
        $this->actAs('a@b.com', ['reports:read']);

        $this->expectException(\Mcp\Exception\ToolCallException::class);
        $this->expectExceptionMessage("Report 'does-not-exist' not found");
        (new ReportMcpTools())->getReport('does-not-exist');
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

    public function testUpdateReportStatusRejectsUnknownReport(): void
    {
        $this->actAs('a@b.com', ['reports:status:write']);

        $this->expectException(\Mcp\Exception\ToolCallException::class);
        $this->expectExceptionMessage("Report 'does-not-exist' not found");
        (new ReportMcpTools())->updateReportStatus('does-not-exist', ReportStatus::Archived);
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

    public function testSetReportNotesRequiresNotesScope(): void
    {
        $app = $this->makeApp('mcp-notes-scope', 'confirmed-waiting', 'a@b.com');
        // reports:status:write must not be enough to write notes.
        $this->actAs('a@b.com', ['reports:read', 'reports:status:write']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires the 'reports:notes:write' OAuth scope");
        (new ReportMcpTools())->setReportNotes($app->id, 'RSOW 1/24');
    }

    public function testSetReportNotesRequiresAtLeastOneField(): void
    {
        $app = $this->makeApp('mcp-notes-empty', 'confirmed-waiting', 'owner6@example.com');
        $this->actAs('owner6@example.com', ['reports:notes:write']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('caseNumber and/or privateNote');
        (new ReportMcpTools())->setReportNotes($app->id);
    }

    public function testSetReportNotesUpdatesOwnReport(): void
    {
        $app = $this->makeApp('mcp-notes-own', 'confirmed-waiting', 'owner7@example.com');
        $this->actAs('owner7@example.com', ['reports:notes:write']);

        $result = (new ReportMcpTools())->setReportNotes($app->id, 'RSOW 42/24', 'zadzwonić w środę');

        // Return value reflects the update.
        self::assertSame('RSOW 42/24', $result['externalId']);
        self::assertSame('zadzwonić w środę', $result['privateComment']);
        // externalId is stored in plaintext, so persistence is verifiable directly.
        self::assertSame('RSOW 42/24', \app\get('mcp-notes-own')->externalId);
    }

    public function testSetReportNotesOnlyCaseNumberLeavesNoteUntouched(): void
    {
        $app = $this->makeApp('mcp-notes-partial', 'confirmed-waiting', 'owner8@example.com');
        $this->actAs('owner8@example.com', ['reports:notes:write']);

        $result = (new ReportMcpTools())->setReportNotes($app->id, 'RSOW 7/24');

        self::assertSame('RSOW 7/24', $result['externalId']);
        // An empty note stays at its default and is omitted from the serialised
        // report (Application::jsonSerialize drops empty externalId/privateComment).
        self::assertSame('', $result['privateComment'] ?? '', 'note must be left as-is when only caseNumber is given');
    }

    public function testSetReportNotesRejectsAnotherUsersReport(): void
    {
        $app = $this->makeApp('mcp-notes-other', 'confirmed-waiting', 'victim3@example.com');
        $this->actAs('attacker3@example.com', ['reports:notes:write']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Report 'mcp-notes-other' not found");
        (new ReportMcpTools())->setReportNotes($app->id, 'RSOW 9/24');

        self::assertSame('', \app\get('mcp-notes-other')->externalId, 'the report must be untouched');
    }

    public function testSetReportNotesRejectsUnknownReport(): void
    {
        // \app\get throws for an unknown id; the tool must still surface a clean
        // "not found" tool error, not an opaque internal exception.
        $this->actAs('owner9@example.com', ['reports:notes:write']);

        $this->expectException(\Mcp\Exception\ToolCallException::class);
        $this->expectExceptionMessage("Report 'does-not-exist' not found");
        (new ReportMcpTools())->setReportNotes('does-not-exist', 'RSOW 1/24');
    }

    public function testListCategoriesRequiresReadScope(): void
    {
        $this->actAs('a@b.com', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires the 'reports:read' OAuth scope");
        (new ReportMcpTools())->listCategories();
    }

    public function testListCategoriesReturnsCatalog(): void
    {
        $this->actAs('cat-reader@example.com', ['reports:read']);

        $result = (new ReportMcpTools())->listCategories();

        self::assertArrayHasKey('categories', $result);
        self::assertNotEmpty($result['categories']);
        $first = $result['categories'][0];
        foreach (['id', 'title', 'formal', 'law', 'fine', 'demeritPoints'] as $key) {
            self::assertArrayHasKey($key, $first);
        }
        self::assertIsInt($first['id']);
    }

    public function testCreateReportDraftRequiresCreateScope(): void
    {
        // Neither read nor status:write may create.
        $this->actAs('a@b.com', ['reports:read', 'reports:status:write']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires the 'reports:create' OAuth scope");
        (new ReportMcpTools())->createReportDraft(category: 8);
    }

    public function testCreateReportDraftCreatesPrefilledDraft(): void
    {
        $this->actAs('creator@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(
            category: 8,
            plateId: 'zs 1234a',
            description: 'auto na chodniku',
            address: 'Mazurska 43, Szczecin',
            lat: 53.43,
            lng: 14.55,
            datetime: '2026-01-08T14:30:00'
        );

        $report = $result['report'];
        self::assertSame('draft', $report['status']);
        self::assertSame(8, $report['category']);
        self::assertSame('ZS 1234A', $report['carInfo']['plateId'], 'plate is whitespace-cleaned + upper-cased (same as the web)');
        self::assertStringContainsString('chodniku', $report['userComment']);
        self::assertSame('Mazurska 43, Szczecin', $report['address']['address']);
        self::assertSame('2026-01-08T14:30:00', $report['date']);
        self::assertStringContainsString('app/new?edit=' . $report['id'], $result['editUrl']);
        // Persisted as a draft the human can still edit.
        self::assertSame('draft', \app\get($report['id'])->status);
    }

    public function testCreateReportDraftRejectsUnknownCategory(): void
    {
        $this->actAs('creator2@example.com', ['reports:create']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown category id');
        (new ReportMcpTools())->createReportDraft(category: 999999);
    }

    public function testCreateReportDraftWithoutLocationKeepsObjectShapedAddress(): void
    {
        $this->actAs('creator-noloc@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(category: 8);

        // The fresh draft's address is an empty object — never a list — so the
        // MCP structuredContent keeps the consistent `{}` shape.
        self::assertSame('{}', json_encode($result['report']['address']));
        // No coordinates → no recipient to resolve (unknown unit, hidden).
        self::assertArrayNotHasKey('recipientInfo', $result['report']);
        self::assertArrayNotHasKey('destinationOptions', $result['report']);
        // Mirrors the web: a fresh User defaults to Policja (User::$data->
        // stopAgresji = true), which is the preference inherited here.
        self::assertSame('police', $result['report']['destination'], 'defaults to the user preference');
    }

    public function testCreateReportDraftReverseGeocodesCoordinates(): void
    {
        ReportMcpTools::setReverseGeocoder(function (float $lat, float $lng) {
            self::assertSame(53.43, $lat);
            self::assertSame(14.55, $lng);
            return [
                'address' => [
                    'address' => 'Mazurska 43, Szczecin',
                    'road' => 'Mazurska',
                    'house_number' => '43',
                    'city' => 'Szczecin',
                    'voivodeship' => 'zachodniopomorskie',
                    'postcode' => '70-000',
                    'county' => 'Szczecin',
                    'municipality' => 'Szczecin',
                ],
                'sm' => new \SM(json_encode([
                    'address' => ['Straż Miejska w Szczecinie', 'ul. Mariacka 1, 70-546 Szczecin'],
                    'email' => 'zgloszenia@sm.szczecin.pl',
                    'city' => 'Szczecin',
                    'hint' => null,
                    'api' => null,
                    'active' => true,
                ])),
                'sa' => new \Police(json_encode([
                    'address' => ['Komenda Miejska Policji w Szczecinie', 'pl. Stefana Batorego 4, 70-207 Szczecin'],
                    'email' => 'kmp.szczecin@sc.policja.gov.pl',
                    'city' => 'Szczecin',
                    'hint' => null,
                    'api' => null,
                    'active' => true,
                ])),
            ];
        });
        $this->actAs('creator-geo@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(
            category: 8,
            destination: 'sm',
            address: 'Mazurska 43, Szczecin',
            lat: 53.43,
            lng: 14.55
        );

        $report = $result['report'];
        // The caller's string stays the display address; the geocoded full string
        // lands in addressGPS — mirrors the web's lokalizacja vs addressGPS split.
        self::assertSame('Mazurska 43, Szczecin', $report['address']['address']);
        self::assertSame('Mazurska 43, Szczecin', $report['address']['addressGPS']);
        self::assertSame('Szczecin', $report['address']['city']);
        self::assertSame('zachodniopomorskie', $report['address']['voivodeship']);
        self::assertSame('70-000', $report['address']['postcode']);
        // The coordinates resolve a real recipient (Straż Miejska), stored as smCity.
        self::assertSame('sm', $report['destination']);
        self::assertSame('zgloszenia@sm.szczecin.pl', $report['recipientInfo']['email']);
        self::assertFalse($report['recipientInfo']['isPolice']);
        self::assertSame('szczecin', \app\get($report['id'])->smCity);
        // Both editor radio options, pre-resolved from the geocoded address.
        self::assertSame('zgloszenia@sm.szczecin.pl', $report['destinationOptions']['sm']['email']);
        self::assertTrue($report['destinationOptions']['police']['isPolice']);
        self::assertSame('kmp.szczecin@sc.policja.gov.pl', $report['destinationOptions']['police']['email']);
    }

    public function testCreateReportDraftWithAddressOnlySkipsGeocoding(): void
    {
        $geocoderCalled = false;
        ReportMcpTools::setReverseGeocoder(function () use (&$geocoderCalled) {
            $geocoderCalled = true;
            return null;
        });
        $this->actAs('creator-addr@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(address: 'Mazurska 43, Szczecin');

        // The web never forward-geocodes a bare address string; neither may MCP.
        self::assertFalse($geocoderCalled, 'no coordinates → no reverse geocoding');
        self::assertSame('Mazurska 43, Szczecin', $result['report']['address']['address']);
        self::assertArrayNotHasKey('lat', $result['report']['address']);
        self::assertArrayNotHasKey('recipientInfo', $result['report'], 'no coordinates → no recipient resolved');
    }

    public function testCreateReportDraftWithDestinationPoliceRoutesToPolice(): void
    {
        $this->actAs('creator-dst@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(category: 8, destination: 'police');

        $report = $result['report'];
        self::assertSame('police', $report['destination']);
        self::assertTrue(\app\get($report['id'])->stopAgresji(), 'destination police is stored as stopAgresji, like the web radio');
        // No coordinates → no address to resolve a concrete unit from (smCity is
        // only stored once a structured address exists), so no recipientInfo yet.
        self::assertArrayNotHasKey('recipientInfo', $report);
        self::assertFalse($report['stopAgresjiForced'], 'a voluntary police choice is not category-forced');
    }

    public function testCreateReportDraftRejectsInvalidDestination(): void
    {
        $this->actAs('creator-dst2@example.com', ['reports:create']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid destination 'poliza' — use 'police' or 'sm'.");
        (new ReportMcpTools())->createReportDraft(destination: 'poliza');
    }

    public function testCreateReportDraftForcesPoliceForStopAgresjiOnlyCategory(): void
    {
        // Category 18 ("Jazda po chodniku") is stopAgresjiOnly: the editor
        // disables the SM radio and the report must go to the police, even
        // though the caller asked for the city guard and the coordinates
        // resolve an SM unit.
        ReportMcpTools::setReverseGeocoder(fn (): array => [
            'address' => [
                'address' => 'Mazurska 43, Szczecin',
                'city' => 'Szczecin',
                'voivodeship' => 'zachodniopomorskie',
                'postcode' => '70-000',
                'county' => 'Szczecin',
                'municipality' => 'Szczecin',
            ],
            'sm' => new \SM(json_encode([
                'address' => ['Straż Miejska w Szczecinie', 'ul. Mariacka 1, 70-546 Szczecin'],
                'email' => 'zgloszenia@sm.szczecin.pl',
                'city' => 'Szczecin',
                'hint' => null,
                'api' => null,
                'active' => true,
            ])),
            'sa' => null,
        ]);
        $this->actAs('creator-forced@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(
            category: 18,
            destination: 'sm',
            address: 'Mazurska 43, Szczecin',
            lat: 53.43,
            lng: 14.55
        );

        $report = $result['report'];
        self::assertSame('police', $report['destination'], 'stopAgresjiOnly categories always go to the police');
        self::assertTrue($report['stopAgresjiForced'], 'the flip is flagged as category-forced, like the web');
        self::assertTrue(\app\get($report['id'])->stopAgresji());
        self::assertNull($report['destinationOptions']['police'], 'a missing police unit degrades to null, not an error');
        self::assertSame('zgloszenia@sm.szczecin.pl', $report['destinationOptions']['sm']['email']);
    }

    public function testCreateReportDraftRejectsInvalidImageDataUri(): void
    {
        $this->actAs('creator3@example.com', ['reports:create']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('base64 data URI');
        (new ReportMcpTools())->createReportDraft(description: 'x', contextImage: 'not-a-data-uri');
    }

    public function testCreateReportDraftRejectsOversizedImage(): void
    {
        $this->actAs('creator4@example.com', ['reports:create']);

        // > 2 MB decoded — rejected before any draft is created.
        $oversized = 'data:image/jpeg;base64,' . base64_encode(str_repeat('a', 2 * 1024 * 1024 + 1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');
        (new ReportMcpTools())->createReportDraft(contextImage: $oversized);
    }

    public function testCreateReportDraftRejectsUnsupportedImageType(): void
    {
        $this->actAs('creator5@example.com', ['reports:create']);

        // A valid GIF (right size, valid base64) but not a JPEG/PNG the pipeline
        // handles — rejected up front, before any draft is created.
        $gd = imagecreatetruecolor(10, 10);
        ob_start();
        imagegif($gd);
        $gif = ob_get_clean();
        imagedestroy($gd);
        $dataUri = 'data:image/gif;base64,' . base64_encode($gif);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JPEG or PNG');
        (new ReportMcpTools())->createReportDraft(contextImage: $dataUri);
    }
}
