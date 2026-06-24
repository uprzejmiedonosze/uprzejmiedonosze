<?php

namespace UprzejmieDonosze\Tests\Dataclasses;

use PHPUnit\Framework\TestCase;

class PoliceTest extends TestCase
{
    public function testOtwock(): void
    {
        $police = new \Police($this->getData('otwock'));
        self::assertEquals('Komenda Powiatowa Policji w Otwocku \\\\ ul. Pułaskiego 7a \\\\ 05-400 Otwock', $police->getLatexAddress());
        self::assertEquals('KPP w Otwocku', $police->getShortName());
        self::assertFalse($police->hasAPI());
        self::assertTrue($police->isPolice());
        self::assertEquals('Police Otwock', $police->getCity());
    }

    public function testGuessByCoordinates(): void
    {
        // a point well inside the Szczecin-Niebuszewo precinct polygon
        $key = \Police::guess(new \JSONObject([
            'city' => 'Szczecin',
            'lat' => 53.438194860526316,
            'lng' => 14.548868789473685,
        ]));
        self::assertEquals('szczecin-niebuszewo', $key);
    }

    public function testGuessFallsThroughToNullWhenNothingMatches(): void
    {
        $key = \Police::guess(new \JSONObject([
            'city' => '__nonexistent__',
            'county' => '__nonexistent__',
            'municipality' => '__nonexistent__',
        ]));
        self::assertNull($key);
    }

    private function getData(string $city): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../export/public/api/config/police.json'), true)[$city];
    }
}
