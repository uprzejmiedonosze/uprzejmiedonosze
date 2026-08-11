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

    protected function tearDown(): void
    {
        // Test overrides are static and shared with CreateReportDraftImageTest;
        // reset them so test order cannot select a stale stub or a live API.
        ReportMcpTools::setReverseGeocoder(null);
        ReportMcpTools::setVehicleInfoFetcher(null);
        parent::tearDown();
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

    /** A minimal JPEG with a GPS-bearing EXIF APP1 segment (same fixture as ExifGpsTest). */
    private function jpegWithExifGps(float $lat, float $lng): string
    {
        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        return "\xFF\xD8" . $this->exifApp1($lat, $lng) . substr($jpeg, 2);
    }

    private function exifApp1(float $lat, float $lng): string
    {
        $latParts = $this->rationalize($lat);
        $lngParts = $this->rationalize($lng);

        // GPS IFD at offset 26 of the TIFF block: 8-byte header + 18-byte IFD0.
        $gpsIfd = pack('v', 4) // entry count
            . $this->exifEntry(0x0001, 2, 2, "N\x00\x00\x00")    // GPSLatitudeRef
            . $this->exifEntry(0x0002, 5, 3, pack('V', 80))      // GPSLatitude → 3 rationals at 80
            . $this->exifEntry(0x0003, 2, 2, "E\x00\x00\x00")    // GPSLongitudeRef
            . $this->exifEntry(0x0004, 5, 3, pack('V', 104))     // GPSLongitude → 3 rationals at 104
            . pack('V', 0);                                      // next IFD pointer

        $rationals = '';
        foreach ($latParts as [$num, $den]) {
            $rationals .= pack('VV', $num, $den);
        }
        foreach ($lngParts as [$num, $den]) {
            $rationals .= pack('VV', $num, $den);
        }

        // IFD0 at offset 8: a single GPSInfo pointer (tag 0x8825) to offset 26.
        $ifd0 = pack('v', 1)
            . $this->exifEntry(0x8825, 4, 1, pack('V', 26))
            . pack('V', 0);

        $tiff = 'II' . pack('v', 42) . pack('V', 8) . $ifd0 . $gpsIfd . $rationals;
        $app1 = "Exif\x00\x00" . $tiff;
        // JPEG marker lengths are big-endian (the TIFF block itself is 'II' LE).
        return "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;
    }

    /** A 4-byte IFD entry value (all values used here fit inline or are offsets). */
    private function exifEntry(int $tag, int $type, int $count, string $value): string
    {
        return pack('vvV', $tag, $type, $count) . $value;
    }

    /** D°M'S as unsigned 32-bit rationals. */
    private function rationalize(float $value): array
    {
        $deg = (int) floor($value);
        $min = (int) floor(($value - $deg) * 60);
        $sec = ($value - $deg - $min / 60) * 3600;
        $secNum = (int) round($sec * 1000);
        return [[$deg, 1], [$min, 1], [$secNum, 1000]];
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
        self::assertSame('sm', $result['reports'][0]['destination']);
        self::assertArrayHasKey('destinationOptions', $result['reports'][0]);
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
        // Destination fields must survive a fresh get_report call, not only the
        // immediate create_report_draft response.
        self::assertSame('sm', $result['destination']);
        self::assertSame('zgloszenia@sm.szczecin.pl', $result['destinationOptions']['sm']['email']);
        self::assertTrue($result['destinationOptions']['police']['isPolice']);
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
        // The public names used by set_report_notes' input round-trip in the output.
        self::assertSame('RSOW 42/24', $result['caseNumber']);
        self::assertSame('zadzwonić w środę', $result['privateNote']);
        // externalId is stored in plaintext, so persistence is verifiable directly.
        self::assertSame('RSOW 42/24', \app\get('mcp-notes-own')->externalId);
    }

    public function testSetReportNotesOnlyCaseNumberLeavesNoteUntouched(): void
    {
        $app = $this->makeApp('mcp-notes-partial', 'confirmed-waiting', 'owner8@example.com');
        $this->actAs('owner8@example.com', ['reports:notes:write']);

        $result = (new ReportMcpTools())->setReportNotes($app->id, 'RSOW 7/24');

        self::assertSame('RSOW 7/24', $result['externalId']);
        self::assertSame('RSOW 7/24', $result['caseNumber']);
        // An empty note stays at its default and is omitted from the serialised
        // report (Application::jsonSerialize drops empty externalId/privateComment).
        self::assertSame('', $result['privateComment'] ?? '', 'note must be left as-is when only caseNumber is given');
        self::assertArrayNotHasKey('privateNote', $result, 'empty note must not add a privateNote key');
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
        // Same stable order as the create_report_draft category enum.
        $ids = array_column($result['categories'], 'id');
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids, 'categories must come in ascending id order');
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
        // Hermetic: the plate triggers the zbiorkom enrichment — stub it so the
        // test never depends on the live endpoint.
        ReportMcpTools::setVehicleInfoFetcher(fn (string $plate): array => ['error' => 'Vehicle not found']);
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

    public function testCreateReportDraftStoresExtensionsAndWitness(): void
    {
        $this->actAs('creator-ext@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(
            category: 16,
            extensions: [11, '25'],
            witness: true
        );

        $report = $result['report'];
        self::assertSame([11, 25], $report['extensions'], 'extension ids are int-cast like the web flow');
        self::assertTrue($report['statements']['witness']);
        $saved = \app\get($report['id']);
        self::assertSame([11, 25], $saved->extensions, 'extensions persist to the draft');
        self::assertTrue($saved->statements->witness, 'witness persists to the draft');
    }

    public function testCreateReportDraftRejectsUnknownExtension(): void
    {
        $this->actAs('creator-ext2@example.com', ['reports:create']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown extension category id');
        (new ReportMcpTools())->createReportDraft(category: 16, extensions: [999999]);
    }

    public function testCreateReportDraftRejectsValidCategoryThatIsNotAnOfferedExtension(): void
    {
        $this->actAs('creator-ext4@example.com', ['reports:create']);

        // 8 is a valid category but not one of the extensions.json entries the
        // web editor offers — the MCP tool must mirror that closed set.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown extension category id 8 — valid extensions: 11');
        (new ReportMcpTools())->createReportDraft(category: 16, extensions: [8]);
    }

    public function testCreateReportDraftRejectsPrimaryCategoryAsExtension(): void
    {
        $this->actAs('creator-ext5@example.com', ['reports:create']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Extension id 11 is the report's primary category");
        (new ReportMcpTools())->createReportDraft(category: 11, extensions: [11]);
    }

    public function testCreateReportDraftRejectsDuplicateExtensions(): void
    {
        $this->actAs('creator-ext6@example.com', ['reports:create']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate extension id 25');
        (new ReportMcpTools())->createReportDraft(category: 16, extensions: [25, '25']);
    }

    public function testCreateReportDraftLeavesWitnessFalseWhenOmitted(): void
    {
        $this->actAs('creator-ext3@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(category: 8);

        // Mirrors the web default (checkbox unchecked): not a witness of the moment itself.
        self::assertFalse($result['report']['statements']['witness']);
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
        // Both editor radio options, pre-resolved from the geocoded address via
        // the same guess/resolve path that stores smCity (never the Nominatim
        // mock's own sm/sa), so options can't diverge from the real recipient.
        self::assertSame('zgloszenia@sm.szczecin.pl', $report['destinationOptions']['sm']['email']);
        self::assertTrue($report['destinationOptions']['police']['isPolice']);
        self::assertSame('sekretariat.srodmiescie@sc.policja.gov.pl', $report['destinationOptions']['police']['email']);
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
        // The police option resolves from the geocoded address itself — the mock's
        // 'sa' => null only means Nominatim had no unit, not that the address can't
        // be routed to the police (the station covering the coordinates wins).
        self::assertSame('sekretariat.srodmiescie@sc.policja.gov.pl', $report['destinationOptions']['police']['email']);
        self::assertSame('zgloszenia@sm.szczecin.pl', $report['destinationOptions']['sm']['email']);
    }

    public function testCreateReportDraftForcesPoliceForStopAgresjiOnlyCategoryWithoutLocation(): void
    {
        $this->actAs('creator-forced2@example.com', ['reports:create']);

        // No coordinates and no address: the unit can't be resolved, but a
        // stopAgresjiOnly report can never go to the city guard — the draft
        // must default to the police just like the editor's disabled radio.
        $result = (new ReportMcpTools())->createReportDraft(category: 18, destination: 'sm');

        $report = $result['report'];
        self::assertSame('police', $report['destination']);
        self::assertTrue($report['stopAgresjiForced']);
        self::assertTrue(\app\get($report['id'])->stopAgresji());
        self::assertArrayNotHasKey('destinationOptions', $report, 'no geocoded address → no pre-resolved options');
    }

    public function testCreateReportDraftNeverMixesSingleCoordinateWithExifGps(): void
    {
        $geocoderCalled = false;
        ReportMcpTools::setReverseGeocoder(function () use (&$geocoderCalled) {
            $geocoderCalled = true;
            return null;
        });
        // Hermetic: this test uploads a real image (ALPR may populate a plate),
        // so stub the zbiorkom lookup rather than hitting the live endpoint.
        ReportMcpTools::setVehicleInfoFetcher(fn (string $plate): array => ['error' => 'Vehicle not found']);
        // uploadImage resolves the user's number from the store — persist the
        // identity like a real registered user would have (plain json; the
        // session-based encryption isn't active in tests).
        $user = $this->actAs('creator-exif2@example.com', ['reports:create']);
        $user->number = 900001;
        \user\save($user, true);

        // The caller supplies only the latitude; the photo carries GPS. The
        // draft must keep exactly the caller's coordinate — never a pair of
        // caller's lat + photo's lng that would reverse-geocode a wrong point.
        $result = (new ReportMcpTools())->createReportDraft(
            lat: 53.43,
            carImage: 'data:image/jpeg;base64,' . base64_encode($this->jpegWithExifGps(50.06, 19.94))
        );

        $report = $result['report'];
        self::assertSame(53.43, $report['address']['lat']);
        self::assertArrayNotHasKey('lng', $report['address']);
        self::assertFalse($geocoderCalled, 'a single coordinate must not be paired with EXIF GPS');
        self::assertArrayNotHasKey('destinationOptions', $report);
    }

    public function testCreateReportDraftAppendsZbiorkomBrandModelAndWeightLines(): void
    {
        ReportMcpTools::setVehicleInfoFetcher(function (string $plate) {
            self::assertSame('WA12345', $plate, 'the fetcher gets the normalized plate');
            return [
                'brand' => 'fiat',
                'model' => 'Punto',
                'vehicleInfo' => ['grossVehicleWeight' => 2600],
            ];
        });
        $this->actAs('creator-zbiorkom@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(
            plateId: 'wa 12345',
            description: 'Parkuje na chodniku'
        );

        $lines = explode("\n", $result['report']['userComment']);
        self::assertSame('Parkuje na chodniku.', $lines[0], 'caller text is kept (capitalized like the web)');
        self::assertContains('Pojazd marki Fiat Punto.', $lines);
        self::assertContains('Dopuszczalna masa całkowita wg danych producenta wynosi minimum 2,60 t.', $lines);
    }

    public function testCreateReportDraftAppendsHeavyTruckLines(): void
    {
        ReportMcpTools::setVehicleInfoFetcher(fn (string $plate): array => [
            'brand' => 'Volvo',
            'model' => 'FH',
            'isHeavyVehicle' => true,
            'vehicleType' => 'TRUCK',
            'vehicleInfo' => ['grossVehicleWeight' => 18000],
        ]);
        $this->actAs('creator-zbiorkom2@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(plateId: 'GDA12345');

        $lines = explode("\n", $result['report']['userComment']);
        self::assertContains('Pojazd marki Volvo FH.', $lines);
        self::assertContains('Pojazd jest sklasyfikowany jako ciężarowy.', $lines);
        self::assertContains('Dopuszczalna masa całkowita wg danych producenta wynosi minimum 18,00 t.', $lines);
        self::assertContains('Może to mieć istotne znaczenie przy kwalifikacji wykroczenia.', $lines);
    }

    public function testCreateReportDraftIgnoresZbiorkomMissAndDeduplicates(): void
    {
        ReportMcpTools::setVehicleInfoFetcher(fn (string $plate): array => ['error' => 'Vehicle not found']);
        $this->actAs('creator-zbiorkom3@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(
            plateId: 'WA99999',
            description: 'Pojazd marki BMW X5.'
        );

        self::assertSame('Pojazd marki BMW X5.', $result['report']['userComment'], 'a miss changes nothing');
    }

    public function testCreateReportDraftSkipsEnrichmentWithoutPlate(): void
    {
        $called = false;
        ReportMcpTools::setVehicleInfoFetcher(function () use (&$called) {
            $called = true;
            return null;
        });
        $this->actAs('creator-zbiorkom4@example.com', ['reports:create']);

        $result = (new ReportMcpTools())->createReportDraft(description: 'Bez rejestracji');

        self::assertFalse($called, 'no plate → no zbiorkom lookup');
        self::assertSame('Bez rejestracji.', $result['report']['userComment']);
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
