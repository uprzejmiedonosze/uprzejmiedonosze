<?php

namespace UprzejmieDonosze\Tests\Dataclasses;

use JSONObject;
use PHPUnit\Framework\TestCase;

class SMTest extends TestCase
{
    public function testPoznan(): void
    {
        $sm = new \SM($this->getData('poznań'));
        self::assertEquals('Straż Miejska Miasta Poznania \\\\ ul. Głogowska 26 \\\\ 60-734 Poznań', $sm->getLatexAddress());
        self::assertEquals('SM Miasta Poznania', $sm->getShortName());
        //self::assertTrue($sm->hasAPI());
        self::assertFalse($sm->isPolice());
    }

    public function testWarsaw(): void
    {
        $sm = new \SM($this->getData('warszawa_ot1'));
        self::assertEquals('I Oddział Terenowy \\\ Straży Miejskiej m.st. Warszawy \\\ ul. Sołtyka 8/10 \\\ 01-163 Warszawa', $sm->getLatexAddress());
        self::assertEquals('I OT', $sm->getShortName());
        self::assertFalse($sm->hasAPI());
        self::assertFalse($sm->isPolice());
    }

    public function testUnknown(): void
    {
        $sm = new \SM($this->getData('_nieznane'));
        self::assertEquals('(skontaktuj się z autorem: szymon@uprzejmiedonosze.net \\\\ i podaj mu adres siedziby oraz e-mail Straży Miejskiej)', $sm->getLatexAddress());
        self::assertEquals('(skontaktuj się z autorem: szymon@uprzejmiedonosze.net', $sm->getShortName());
        self::assertFalse($sm->hasAPI());
        self::assertFalse($sm->isPolice());
    }

    public function testInactiveSMSkippedByGuessButKeptByResolve() : void {
        $naleczow = new \SM($this->getData('nałęczów'));
        self::assertFalse($naleczow->active);

        $guessedKey = \SM::guess(new JSONObject(['county' => 'gmina Nałęczów', 'municipality' => 'powiat puławski', 'city' => 'Nałęczów']));
        self::assertEquals('powiat puławski', $guessedKey);

        $resolved = \SM::resolve('nałęczów', false);
        self::assertEquals($naleczow->getEmail(), $resolved->getEmail());
        self::assertEquals($naleczow->getLatexAddress(), $resolved->getLatexAddress());
        self::assertFalse($resolved->active);
    }

    public function testGuessSM() : void {
        $sm1 = new \SM($this->getData('dziwnów'));
        $sm2Key = \SM::guess(new JSONObject(['county' => 'gmina Dziwnów', 'city' => 'Międzywodzie']));
        $sm2 = new \SM($this->getData($sm2Key));
        self::assertEquals($sm1, $sm2);

        $byCity = new \SM($this->getData(\SM::guess(new JSONObject(['county' => '__', 'city' => 'Oświęcim']))));
        $byCounty = new \SM($this->getData(\SM::guess(new JSONObject(['county' => 'gmina Chełmek', 'city' => '__']))));
        $byReference = new \SM($this->getData(\SM::guess(new JSONObject(['county' => 'gmina Oświęcim', 'city' => '__']))));

        self::assertEquals($byCity->getEmail(), $byCounty->getEmail());
        self::assertEquals($byCity->getEmail(), $byReference->getEmail());
    }

    /**
     * Wieś Józefów w gminie Ożarów Mazowiecki koliduje nazwą z miastem
     * Józefów (powiat otwocki). Klucz kodu pocztowego "05-860" (patrz
     * GitHub issue #120) ma wyprzedzać dopasowanie po nazwie miasta.
     */
    public function testGuessByPostcodeBeforeCityName() : void {
        $smOzarow = new \SM($this->getData('ożarów mazowiecki'));

        $village = \SM::guess(new JSONObject([
            'postcode' => '05-860',
            'county' => 'gmina Ożarów Mazowiecki',
            'municipality' => 'powiat warszawski zachodni',
            'voivodeship' => 'mazowieckie',
            'city' => 'Józefów'
        ]));
        self::assertEquals('05-860', $village);
        self::assertEquals($smOzarow->getEmail(), (new \SM($this->getData($village)))->getEmail());

        $city = \SM::guess(new JSONObject([
            'postcode' => '05-420',
            'county' => 'gmina Józefów',
            'city' => 'Józefów'
        ]));
        self::assertEquals('józefów', $city);
        self::assertEquals('straz.miejska@jozefow.pl', (new \SM($this->getData($city)))->getEmail());
    }

    private function getData(string $city): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../export/public/api/config/sm.json'), true)[$city];
    }
}
