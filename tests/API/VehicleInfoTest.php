<?php

namespace UprzejmieDonosze\Tests\API;

use app\Application;
use PHPUnit\Framework\TestCase;

class VehicleInfoTest extends TestCase
{
    protected function tearDown(): void
    {
        \vehicle_info\setFetcher(fn (string $plate): ?array => null);
        parent::tearDown();
    }

    public function testNormalizePlate(): void
    {
        $this->assertSame('WA12345', \vehicle_info\normalizePlate(" wa 12\t345 "));
        $this->assertNull(\vehicle_info\normalizePlate('WA1'));
        $this->assertNull(\vehicle_info\normalizePlate(null));
    }

    public function testLookupFormatsBrand(): void
    {
        \vehicle_info\setFetcher(fn (string $plate): array => ['brand' => 'SSANGYONG', 'model' => ' Rexton ']);
        $info = \vehicle_info\lookup('ZS12331');
        $this->assertSame('SsangYong', $info['brand']);
        $this->assertSame('Rexton', $info['model']);
        $this->assertSame('ZS12331', $info['plateId']);
        $this->assertNull($info['warning']);

        \vehicle_info\setFetcher(fn (string $plate): array => ['brand' => 'bmw', 'model' => 'X5']);
        $this->assertSame('BMW', \vehicle_info\lookup('ZS12331')['brand']);
    }

    public function testLookupMiss(): void
    {
        \vehicle_info\setFetcher(fn (string $plate): array => ['error' => 'Vehicle not found']);
        $this->assertNull(\vehicle_info\lookup('ZS12331'));

        \vehicle_info\setFetcher(fn (string $plate): ?array => null);
        $this->assertNull(\vehicle_info\lookup('ZS12331'));
    }

    public function testRefreshFollowsPlateChanges(): void
    {
        $calls = 0;
        \vehicle_info\setFetcher(function (string $plate) use (&$calls): ?array {
            $calls++;
            return $plate === 'ZS12331' ? ['brand' => 'VOLVO', 'model' => 'XC60'] : null;
        });

        $app = Application::withJson('{"statusHistory":{},"comments":[],"extensions":[],"user":{"name":"Ud Developer","sex":"m"},"carInfo":{"plateId":"ZS 12331"}}', 'vehicle@example.com');
        \vehicle_info\refresh($app);
        $this->assertSame('Volvo', $app->carInfo->vehicle->brand);
        $this->assertSame('ZS 12331 (pojazd marki Volvo XC60)', $app->getPlateDescription());

        \vehicle_info\refresh($app);
        $this->assertSame(1, $calls, 'same plate → no new lookup');

        $app->carInfo->plateId = 'ZS99999';
        \vehicle_info\refresh($app);
        $this->assertObjectNotHasProperty('vehicle', $app->carInfo, 'unknown new plate drops the stale vehicle');
        $this->assertSame('ZS99999', $app->getPlateDescription());
    }
}
