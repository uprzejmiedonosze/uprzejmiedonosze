<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/handlers/SessionApiHandler.php'; // loads API.php → Geolocation.php (plain require there; never require_once it separately)

use PHPUnit\Framework\TestCase;

/** ADR 0007: gmina/powiat from Nominatim zoom=10, everything else from zoom=18. */
class NominatimAdminTest extends TestCase
{
    // Real Nominatim answers for 51.0492,16.9555 (OBI Bielany Wrocławskie; GUGiK: gmina Kobierzyce).
    private const Z18 = [
        'shop' => 'Aleja Bielany', 'road' => 'A4;5', 'village' => 'Zabrodzie',
        'municipality' => 'gmina Kąty Wrocławskie', 'county' => 'powiat wrocławski',
        'state' => 'województwo dolnośląskie', 'postcode' => '52-340', 'country_code' => 'pl',
    ];
    private const Z10 = [
        'village' => 'Bielany Wrocławskie', 'municipality' => 'gmina Kobierzyce',
        'county' => 'powiat wrocławski', 'state' => 'województwo dolnośląskie', 'postcode' => '55-077',
    ];

    public function testGminaComesFromZoom10(): void
    {
        $a = \geo\withAdminBoundaries(self::Z18, self::Z10);
        $this->assertSame('gmina Kobierzyce', $a['municipality']);
        $this->assertSame('powiat wrocławski', $a['county']);
    }

    public function testRestOfTheAddressStaysFromZoom18(): void
    {
        $a = \geo\withAdminBoundaries(self::Z18, self::Z10);
        $this->assertSame('Zabrodzie', $a['village']);
        $this->assertSame('52-340', $a['postcode']);
        $this->assertSame('A4;5', $a['road']);
    }

    public function testBorderPointResolvesToKobierzyceUnit(): void
    {
        $a = \geo\withAdminBoundaries(self::Z18, self::Z10);
        // same swap as in \geo\Nominatim(): "gmina …" → county, "powiat …" → municipality
        $addr = (object)[
            'city' => $a['village'], 'postcode' => $a['postcode'],
            'county' => $a['municipality'], 'municipality' => $a['county'],
            'voivodeship' => 'dolnośląskie',
        ];
        $this->assertSame('kobierzyce', \Police::guess($addr));
    }

    public function testCityWithoutAdminLevelKeepsZoom18Values(): void
    {
        $z18 = ['road' => 'Piaseczyńska', 'suburb' => 'Mokotów', 'city' => 'Warszawa', 'postcode' => '00-765'];
        $this->assertSame($z18, \geo\withAdminBoundaries($z18, ['city' => 'Warszawa']));
    }

    public function testFailedAdminLookupKeepsZoom18Values(): void
    {
        $this->assertSame(self::Z18, \geo\withAdminBoundaries(self::Z18, null));
    }
}
