<?php

namespace UprzejmieDonosze\Tests\Dataclasses;

use PHPUnit\Framework\TestCase;

class PolicjaTest extends TestCase
{
    public function testOtwock(): void
    {
        $policja = new \Policja($this->getData('otwock'));
        self::assertEquals('Komenda Powiatowa Policji w Otwocku \\\\ ul. Pułaskiego 7a \\\\ 05-400 Otwock', $policja->getLatexAddress());
        self::assertEquals('KPP w Otwocku', $policja->getShortName());
        self::assertFalse($policja->hasAPI());
        self::assertTrue($policja->isPolice());
        self::assertEquals('Policja Otwock', $policja->getCity());
    }

    public function testGuessByCoordinates(): void
    {
        // a point well inside the Szczecin-Niebuszewo precinct polygon
        $key = \Policja::guess(new \JSONObject([
            'city' => 'Szczecin',
            'lat' => 53.438194860526316,
            'lng' => 14.548868789473685,
        ]));
        self::assertEquals('szczecin-niebuszewo', $key);
    }

    public function testGuessFallsThroughToNullWhenNothingMatches(): void
    {
        $key = \Policja::guess(new \JSONObject([
            'city' => '__nonexistent__',
            'county' => '__nonexistent__',
            'municipality' => '__nonexistent__',
        ]));
        self::assertNull($key);
    }

    private function getData(string $city): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../export/public/api/config/policja.json'), true)[$city];
    }
}
