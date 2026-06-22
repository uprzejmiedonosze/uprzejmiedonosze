<?php

namespace UprzejmieDonosze\Tests\Dataclasses;

use PHPUnit\Framework\TestCase;

class StopAgresjiTest extends TestCase
{
    public function testDolnoslaskie(): void
    {
        $sm = new \StopAgresji($this->getData('dolnośląskie'));
        self::assertEquals('Komenda Wojewódzka Policji we Wrocławiu \\\\ ul. Podwale 31-33 \\\\ 50-040 Wrocław', $sm->getLatexAddress());
        self::assertEquals('KWP we Wrocławiu', $sm->getShortName());
        self::assertFalse($sm->hasAPI());
        self::assertTrue($sm->isPolice());
    }

    public function testGuessPrefersSpecificPolicjaUnitOverVoivodeship(): void
    {
        // Otwock has a specific Policja entry (no voivodeship-level match needed)
        $key = \StopAgresji::guess(new \JSONObject(['county' => 'Otwock', 'city' => '__']));
        self::assertEquals('otwock', $key);
    }

    public function testGuessFallsBackToVoivodeshipWhenNoSpecificUnit(): void
    {
        $key = \StopAgresji::guess(new \JSONObject([
            'city' => '__nonexistent__',
            'county' => '__nonexistent__',
            'voivodeship' => 'Dolnośląskie',
        ]));
        self::assertEquals('dolnośląskie', $key);
    }

    public function testGuessFallsBackToDefaultWhenNothingMatches(): void
    {
        $key = \StopAgresji::guess(new \JSONObject([
            'city' => '__nonexistent__',
            'county' => '__nonexistent__',
            'voivodeship' => '__nonexistent__',
        ]));
        self::assertEquals('default', $key);
    }

    private function getData(string $city): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../export/public/api/config/stop-agresji.json'), true)[$city];
    }

}
