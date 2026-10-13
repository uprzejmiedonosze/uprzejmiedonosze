<?php

namespace UprzejmieDonosze\Tests\Store;

use app\Application;
use user\User;
use UprzejmieDonosze\Tests\DatabaseTestCase;

/**
 * /zgloszenia.xlsx: \user\appsCursor() streamed into \app\appsToXlsx().
 */
class AppsXlsxExportTest extends DatabaseTestCase
{
    private const EMAIL = 'apps-xlsx@example.com';

    private const APP_JSON_TEMPLATE = '{"date":"2019-03-31T13:06:23","id":"x","added":"2019-04-14T13:22:48","user":{"email":"apps-xlsx@example.com","name":"Ud Developer","number":2,"exposeData":false,"msisdn":"","address":"Rynek 99-120, Piątek"},"status":"confirmed-waiting","category":8,"statements":{"witness":true},"statusHistory":{},"carInfo":{"plateId":"ZS2450C"},"address":{"address":"aleja Papieża Jana Pawła II 36, Szczecin","city":"Szczecin","lat":53.43,"lng":14.54},"smCity":"szczecin","userComment":"","number":"UD/2/2","comments":[],"extensions":[],"seq":2,"version":"2.3.0"}';

    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path)
            if (is_file($path))
                unlink($path);
        parent::tearDown();
    }

    private function app(int $seq, array $overrides = []): Application
    {
        $data = array_replace_recursive(json_decode(self::APP_JSON_TEMPLATE, true), [
            'id' => "xlsx-$seq",
            'seq' => $seq,
            'number' => "UD/2/$seq",
        ], $overrides);
        return Application::withJson(json_encode($data), self::EMAIL);
    }

    private function export(iterable $apps): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ud-xlsx-test-');
        $this->paths[] = $path;
        \app\appsToXlsx($apps, 'Test', $path);
        return $path;
    }

    private function sheetXml(string $path): string
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path) === true, 'export must be a valid XLSX (zip)');
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        self::assertIsString($xml);
        return $xml;
    }

    public function testExportsHeaderAndOneRowPerReportFromDb(): void
    {
        foreach (range(1, 3) as $seq)
            \app\save($this->app($seq));
        $user = new User();
        $user->data->email = self::EMAIL;

        $xml = $this->sheetXml($this->export(\user\appsCursor($user)));

        self::assertSame(4, substr_count($xml, '<row '), 'header + 3 reports');
        self::assertStringContainsString('Uwagi prywatne', $xml);
        self::assertStringContainsString('ZS2450C', $xml);
        self::assertStringContainsString('HYPERLINK(&quot;https://', $xml, 'report number links to the report');
        // newest first, like the list
        self::assertLessThan(strpos($xml, 'UD/2/1'), strpos($xml, 'UD/2/3'));
    }

    public function testUserTextIsNeverAFormula(): void
    {
        $xml = $this->sheetXml($this->export([
            $this->app(1, ['userComment' => '=HYPERLINK("http://evil","x")', 'privateComment' => '=1+1']),
        ]));

        // only the two HYPERLINK cells we generate (number, address) are formulas
        self::assertSame(2, substr_count($xml, '<f>'));
        self::assertStringNotContainsString('<f>1+1</f>', $xml);
        self::assertStringContainsString('=1+1', $xml, 'kept as plain text');
    }

    public function testMemoryDoesNotGrowWithNumberOfReports(): void
    {
        $apps = function (int $count) {
            for ($i = 1; $i <= $count; $i++)
                yield $this->app($i, ['userComment' => str_repeat('komentarz ', 20)]);
        };

        $this->export($apps(200)); // warm-up: autoload, OpenSpout internals
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();

        $path = $this->export($apps(5000));
        $growth = memory_get_peak_usage() - $before;

        self::assertLessThan(16 * 1024 * 1024, $growth,
            sprintf('5000 rows must stream, peak grew by %.1f MB', $growth / 1024 / 1024));
        self::assertSame(5001, substr_count($this->sheetXml($path), '<row '));
    }
}
