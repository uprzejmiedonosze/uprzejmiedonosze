<?php

namespace UprzejmieDonosze\Tests\Dataclasses;

require_once __DIR__ . '/../../src/inc/dataclasses/StopAgresji.php';

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

    private function getData(string $city): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../export/public/api/config/stop-agresji.json'), true)[$city];
    }

}
