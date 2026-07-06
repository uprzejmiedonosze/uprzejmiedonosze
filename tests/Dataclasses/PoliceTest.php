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
        self::assertEquals('Policja Otwock', $police->getCity());
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

    public function testGuessDisambiguatesPowiatByVoivodeship(): void
    {
        // "powiat średzki" istnieje w dwóch województwach (Środa Śląska -
        // dolnośląskie, Środa Wielkopolska - wielkopolskie), a Nominatim
        // zwraca w polu "county" samą nazwę powiatu, bez województwa - stąd
        // klucze w police.json mają zawsze dopisane województwo i dopasowanie
        // buduje klucz "$municipality $voivodeship" zamiast polegać na samej
        // (niejednoznacznej) nazwie powiatu.
        $wielkopolskie = \Police::guess(new \JSONObject([
            'city' => 'Środa Wielkopolska',
            'municipality' => 'powiat średzki',
            'voivodeship' => 'wielkopolskie',
        ]));
        self::assertEquals('powiat średzki wielkopolskie', $wielkopolskie);

        $dolnoslaskie = \Police::guess(new \JSONObject([
            'city' => 'Środa Śląska',
            'municipality' => 'powiat średzki',
            'voivodeship' => 'dolnośląskie',
        ]));
        self::assertEquals('powiat średzki dolnośląskie', $dolnoslaskie);
    }

    public function testGuessDisambiguatesSecondPowiatPair(): void
    {
        $mazowieckie = \Police::guess(new \JSONObject([
            'municipality' => 'powiat ostrowski',
            'voivodeship' => 'mazowieckie',
        ]));
        self::assertEquals('powiat ostrowski mazowieckie', $mazowieckie);

        $wielkopolskie = \Police::guess(new \JSONObject([
            'municipality' => 'powiat ostrowski',
            'voivodeship' => 'wielkopolskie',
        ]));
        self::assertEquals('powiat ostrowski wielkopolskie', $wielkopolskie);
    }

    public function testGuessReturnsNullWhenVoivodeshipUnknownAndPowiatIsAmbiguous(): void
    {
        // wszystkie klucze dla powiatów zdublowanych w dwóch województwach
        // mają dopisane województwo, więc bez informacji o województwie nie
        // ma bezpiecznego domyślnego dopasowania - lepiej zwrócić null (i
        // spaść na kolejny poziom / wojewódzki fallback) niż zgadywać.
        $key = \Police::guess(new \JSONObject([
            'municipality' => 'powiat średzki',
            'voivodeship' => '__nonexistent__',
        ]));
        self::assertNull($key);
    }

    private function getData(string $city): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../export/public/api/config/police.json'), true)[$city];
    }
}
