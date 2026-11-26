<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/handlers/SessionApiHandler.php'; // loads API.php → Geolocation.php (plain require there; never require_once it separately)

use PHPUnit\Framework\TestCase;

/** ADR 0010: rate limit detection and the MapBox stand-in for Nominatim. */
class NominatimFallbackTest extends TestCase
{
    // Real MapBox answers (2026-11-26), in the shape \geo\MapBox() returns them.
    private const MAPBOX = [
        'address' => 'Dworcowa 20b, Szczecin', 'city' => 'Szczecin', 'voivodeship' => 'zachodniopomorskie', 'postcode' => '70-206',
    ];
    private const MAPBOX_WARSAW = [
        'address' => 'Konduktorska 8, Warszawa', 'city' => 'Warszawa', 'voivodeship' => 'mazowieckie', 'postcode' => '00-775',
    ];

    public function testRateLimitStatuses(): void
    {
        $this->assertTrue(\curl\isRateLimited(429));
        $this->assertTrue(\curl\isRateLimited(629));
        $this->assertFalse(\curl\isRateLimited(200));
        $this->assertFalse(\curl\isRateLimited(404));
    }

    public function testResolvesBothRecipients(): void
    {
        $a = \geo\addressFromMapBox(self::MAPBOX, '53.4214', '14.5539');
        $this->assertArrayNotHasKey('municipality', $a);

        $result = \geo\withUnits($a);
        $this->assertNotEmpty($result['sm']->email);
        $this->assertTrue($result['sa']->isPolice());
    }

    public function testWarsawWithoutDistrictHasNoStandIn(): void
    {
        $a = \geo\addressFromMapBox(self::MAPBOX_WARSAW, '52.2000', '21.0300');
        $this->assertFalse(\geo\isEnoughForUnit($a));
    }

    public function testCooldownServesMapBoxAddressWithoutCallingNominatim(): void
    {
        $type = \cache\Type::Nominatim;
        \cache\delete($type, '10.0001,10.0002');
        \cache\geo\set(\cache\Type::MapBox, '10.0001,10.0002', ['address' => self::MAPBOX]);
        \cache\set($type, \geo\NOMINATIM_COOLDOWN_KEY, true, 0, 5);
        try {
            $result = \geo\Nominatim(10.0001, 10.0002);
            $this->assertSame('Szczecin', $result['address']['city']);
            $this->assertNotEmpty($result['sm']->email);
            $this->assertFalse(\cache\get($type, '10.0001,10.0002'), 'stand-in must not be cached');
        } finally {
            \cache\delete($type, \geo\NOMINATIM_COOLDOWN_KEY);
            \cache\delete(\cache\Type::MapBox, '10.0001,10.0002');
        }
    }
}
