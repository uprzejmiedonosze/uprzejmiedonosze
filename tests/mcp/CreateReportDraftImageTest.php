<?php

namespace UprzejmieDonosze\Tests\Mcp;

require_once __DIR__ . '/../../export/inc/mcp/McpIdentity.php';
require_once __DIR__ . '/../../export/inc/mcp/ReportMcpTools.php';
require_once __DIR__ . '/../../export/inc/API.php';

use mcp\McpIdentity;
use mcp\ReportMcpTools;
use PHPUnit\Framework\TestCase;
use user\User;

/**
 * Verifies create_report_draft's optional image is run through the real web
 * upload pipeline (uploadImage → saveImgAndThumb). Uses a context image so ALPR
 * (carImage only) is not exercised. Mirrors tests/API/ImageUploadTest.
 */
class CreateReportDraftImageTest extends TestCase
{
    /** @var list<string> */
    private array $pathsToRemove = [];

    protected function tearDown(): void
    {
        foreach ($this->pathsToRemove as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->pathsToRemove = [];
        $_SESSION = [];
        // Shared static test override; never leak it into another test class.
        ReportMcpTools::setVehicleInfoFetcher(null);
        parent::tearDown();
    }

    private function makeJpegDataUri(int $width = 120, int $height = 90): string
    {
        $img = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($img, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($img);
        return 'data:image/jpeg;base64,' . base64_encode($bytes);
    }

    public function testCreateReportDraftWithImageRunsPipeline(): void
    {
        $_SESSION['user_id'] = 'phpunit-user-id';
        $_SESSION['user_email'] = 'draft-img@example.com';
        $user = new User();
        $user->data->email = 'draft-img@example.com';
        \user\save($user);

        McpIdentity::set($user);
        McpIdentity::setScopes(['reports:create']);

        // Hermetic: the plate triggers the zbiorkom enrichment — stub it so the
        // test never depends on the live endpoint.
        ReportMcpTools::setVehicleInfoFetcher(fn (string $plate): array => ['error' => 'Vehicle not found']);

        $result = (new ReportMcpTools())->createReportDraft(
            plateId: 'zs 1234a',
            description: 'auto na chodniku',
            contextImage: $this->makeJpegDataUri()
        );

        $report = $result['report'];
        $this->pathsToRemove[] = ROOT . $report['contextImage']['url'];
        $this->pathsToRemove[] = ROOT . $report['contextImage']['thumb'];

        self::assertSame('draft', $report['status']);
        self::assertNotEmpty($report['contextImage']['url']);
        self::assertNotEmpty($report['contextImage']['thumb']);
        self::assertSame(120, $report['contextImage']['width']);
        self::assertSame(90, $report['contextImage']['height']);
        self::assertFileExists(ROOT . $report['contextImage']['url']);
        // The supplied plate survives alongside an uploaded image.
        self::assertSame('ZS 1234A', $report['carInfo']['plateId']);
    }
}
