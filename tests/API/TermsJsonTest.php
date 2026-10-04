<?php

namespace UprzejmieDonosze\Tests\API;

use PHPUnit\Framework\TestCase;

/** GET /api/rest/config/terms renders regulamin.json.twig – a stray comma/brace there breaks every client's JSON parse. */
class TermsJsonTest extends TestCase
{
    public function testTermsTemplateRendersValidJson(): void
    {
        $json = \initBareTwig()->render('regulamin.json.twig', ['latestTermUpdate' => '2024-03-26']);
        $data = json_decode($json, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        $this->assertSame('2024-03-26', $data['updated']);
        $this->assertNotEmpty($data['terms']);
        $last = end($data['terms']);
        $this->assertSame('polityce prywatności', $last['li'][1]['link']['text']); // the privacy-policy link item
    }
}
