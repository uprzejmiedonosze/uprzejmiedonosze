<?php

namespace UprzejmieDonosze\Tests;

require_once __DIR__ . '/../export/inc/demoLogin.php';

use PHPUnit\Framework\TestCase;

/**
 * \demo\decide(): prod nigdy nie loguje (najwyżej odsyła na staging), staging loguje tylko z dobrym kodem,
 * a brak konfiguracji wyłącza konto demo zupełnie.
 */
class DemoLoginTest extends TestCase
{
    private const EMAIL = 'review@uprzejmiedonosze.net';
    private const CODE = '493817';

    public function testProdSwitchesDemoEmailToStagingEvenWithCode(): void
    {
        $this->assertSame(\demo\SWITCH_TO_STAGING, \demo\decide(self::EMAIL, '', self::EMAIL, '', true));
        $this->assertSame(\demo\SWITCH_TO_STAGING, \demo\decide(' Review@UprzejmieDonosze.net ', self::CODE, self::EMAIL, self::CODE, true));
    }

    public function testOtherEmailIsNotDemo(): void
    {
        $this->assertSame(\demo\NOT_DEMO, \demo\decide('ktos@example.com', self::CODE, self::EMAIL, self::CODE, true));
        $this->assertSame(\demo\NOT_DEMO, \demo\decide('ktos@example.com', self::CODE, self::EMAIL, self::CODE, false));
    }

    public function testUnconfiguredIsNotDemo(): void
    {
        $this->assertSame(\demo\NOT_DEMO, \demo\decide('', '', '', '', true));
        $this->assertSame(\demo\NOT_DEMO, \demo\decide(self::EMAIL, self::CODE, '', self::CODE, false));
    }

    public function testStagingNeedsMatchingCode(): void
    {
        $this->assertSame(\demo\LOGIN, \demo\decide(self::EMAIL, ' ' . self::CODE . ' ', self::EMAIL, self::CODE, false));
        $this->assertSame(\demo\BAD_CODE, \demo\decide(self::EMAIL, '000000', self::EMAIL, self::CODE, false));
        $this->assertSame(\demo\BAD_CODE, \demo\decide(self::EMAIL, '', self::EMAIL, self::CODE, false));
        // brak kodu w konfiguracji stagingu nie może otwierać konta pustym kodem
        $this->assertSame(\demo\BAD_CODE, \demo\decide(self::EMAIL, '', self::EMAIL, '', false));
    }
}
