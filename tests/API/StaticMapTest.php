<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/handlers/SessionApiHandler.php'; // loads API.php → Geolocation.php (plain require there; never require_once it separately)

use PHPUnit\Framework\TestCase;

/** URL of the report form's static map preview (GET /api/rest/geo/map). */
class StaticMapTest extends TestCase
{
    public function testPinAtThePoint(): void
    {
        $url = \geo\staticMapUrl(53.4285, 14.5528, 600, 300);
        $this->assertStringContainsString('/static/pin-l+0d7a55(14.5528,53.4285)/14.5528,53.4285,15,0/600x300@2x', $url);
        $this->assertStringContainsString('access_token=', $url);
    }

    public function testWithoutPointShowsPolandAndClampsSize(): void
    {
        $url = \geo\staticMapUrl(null, null, 5000, 10);
        $this->assertStringNotContainsString('pin-l', $url);
        $this->assertStringContainsString('/static/19.48,52.07,4.8,0/640x100@2x', $url);
    }
}
