<?php

namespace UprzejmieDonosze\Tests\Dataclasses;

require_once __DIR__ . '/../../export/inc/dataclasses/SM.php';

use PHPUnit\Framework\TestCase;

class SMTest extends TestCase
{
    public function testPoznan(): void
    {
        $sm = new \SM($this->getData('poznań'));
        self::assertEquals('Straż Miejska Miasta Poznania \\\\ ul. Głogowska 26 \\\\ 60-734 Poznań', $sm->getLatexAddress());
        self::assertEquals('SM Miasta Poznania', $sm->getShortName());
        self::assertTrue($sm->hasAPI());
        self::assertFalse($sm->isPolice());
    }

    public function testWarsaw(): void
    {
        $sm = new \SM($this->getData('warszawa_ot1'));
        self::assertEquals('I Oddział Terenowy \\\ Straży Miejskiej m.st. Warszawy \\\ ul. Sołtyka 8/10 \\\ 01-163 Warszawa', $sm->getLatexAddress());
        self::assertEquals('I Oddział Terenowy', $sm->getShortName());
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

    public function testOtwock(): void
    {
        $sm = new \SM($this->getData('otwock'));
        self::assertEquals('Komenda Powiatowa Policji w Otwocku \\\\ ul. Pułaskiego 7a \\\\ 05-400 Otwock', $sm->getLatexAddress());
        self::assertEquals('KPP w Otwocku', $sm->getShortName());
        self::assertFalse($sm->hasAPI());
        self::assertTrue($sm->isPolice());
    }

    private function getData(string $city): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../export/public/api/config/sm.json'), true)[$city];
    }
}
