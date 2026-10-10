<?php

namespace UprzejmieDonosze\Tests\Letter;

require_once __DIR__ . '/../../export/inc/LetterMatcher.php';

use PHPUnit\Framework\TestCase;

use function letter\addressSimilarity;
use function letter\dateSimilarity;
use function letter\damerauLevenshtein;
use function letter\matchCase;
use function letter\plateSimilarity;
use function letter\timeSimilarity;

/** Czysty fuzzy-matching sprawy z pisma do zgłoszeń – bez bazy i sieci. */
class LetterMatcherTest extends TestCase
{
    private static function cand(string $id, string $plate, string $date, string $address, array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'number' => "UD/5/$id", 'plate' => $plate, 'date' => $date, 'address' => $address,
            'externalId' => '', 'privateComment' => '', 'vehicle' => null, 'status' => 'confirmed-waiting',
        ];
    }

    private static function case(array $over = []): array
    {
        return $over + ['ids' => [], 'plate' => null, 'date' => null, 'time' => null, 'address' => [], 'vehicleMake' => null];
    }

    public function testDamerauLevenshtein(): void
    {
        $this->assertSame(0, damerauLevenshtein('XO8T372', 'XO8T372'));
        $this->assertSame(1, damerauLevenshtein('XO8T372', 'XO8T373'));
        $this->assertSame(1, damerauLevenshtein('XO8T372', 'XO8T732'), 'transpozycja = 1');
        $this->assertSame(1, damerauLevenshtein('XO8T372', 'XO8T37'));
        $this->assertSame(7, damerauLevenshtein('', 'XO8T372'));
    }

    public function testPlateSimilarity(): void
    {
        $this->assertSame([1.0, 'exact'], plateSimilarity('XBU ER23', 'xbuer23'), 'spacje i wielkość liter');
        $this->assertSame([1.0, 'exact'], plateSimilarity('XA 6926F', 'XA6926F'));
        $this->assertSame('confusable', plateSimilarity('XB0558Q', 'XBO558Q')[1], '0/O');
        $this->assertSame('fuzzy1', plateSimilarity('XB86847', 'XB8684Z')[1], 'jedna zamiana znaku (A02 poz. 9)');
        $this->assertSame('fuzzy1', plateSimilarity('XO8T372', 'XO8T373')[1]);
        $this->assertSame('fuzzy2', plateSimilarity('XO8T372', 'XO8T399')[1]);
        $this->assertSame('none', plateSimilarity('XO8T372', 'XK4424N')[1]);
        $this->assertSame('none', plateSimilarity(null, 'XK4424N')[1]);
        $this->assertSame('masked', plateSimilarity('XO8?372', 'XO8T372')[1], 'zamazany znak');
        $this->assertSame('none', plateSimilarity('XO8?372', 'XO9T372')[1]);
    }

    public function testShortPlatesAreNotMatchedLoosely(): void
    {
        $this->assertSame('none', plateSimilarity('AB123', 'AB199')[1], 'dystans 2 przy krótkiej tablicy to za mało');
    }

    public function testDateSimilarity(): void
    {
        $this->assertSame([1.0, 'exact'], dateSimilarity('2026-03-06', '2026-03-06T08:10:00'));
        $this->assertSame([0.8, '±1d'], dateSimilarity('2026-03-06', '2026-03-07T08:10:00'));
        $this->assertSame([0.5, '±3d'], dateSimilarity('2026-03-06', '2026-03-09T08:10:00'));
        $this->assertSame('swapped', dateSimilarity('2026-03-06', '2026-06-03T08:10:00')[1], 'zamiana dzień/miesiąc');
        $this->assertSame('sameMonth', dateSimilarity('2026-03-06', '2026-03-25T08:10:00')[1]);
        $this->assertSame('none', dateSimilarity('2026-03-06', '2026-07-06T08:10:00')[1]);
        $this->assertSame('none', dateSimilarity(null, '2026-03-06T08:10:00')[1]);
    }

    public function testWrongYearInTheLetterIsAPartialMatchNotADisqualification(): void
    {
        $this->assertSame([0.7, 'otherYear'], dateSimilarity('2025-07-30', '2026-07-30T19:24:00'));
        $this->assertSame([0.7, 'otherYear'], dateSimilarity('2027-07-30', '2026-07-30T19:24:00'));
        $this->assertSame('none', dateSimilarity('2024-07-30', '2026-07-30T19:24:00')[1], 'dwa lata różnicy to już inny dzień');
        $this->assertSame('sameMonth', dateSimilarity('2026-07-12', '2026-07-30T19:24:00')[1]);
    }

    public function testReportThatMatchesInEverythingButTheYearIsStillFound(): void
    {
        // zgłoszenie wpisane „na 100%”, pismo ma rok o 1 inny: dawało 45% („nie znaleziono”), bo brak daty ucinał wynik
        $cands = [self::cand('1', 'XND30380', '2026-07-30T19:24:00', 'Poznańska 21, Warszawa', ['vehicle' => 'Honda Civic'])];
        $r = matchCase(self::case([
            'plate' => 'XND30380', 'date' => '2025-07-30', 'time' => '19:24', 'vehicleMake' => 'Honda',
            'address' => ['street' => 'POZNAŃSKA', 'number' => '21', 'city' => 'Warszawa'],
        ]), $cands, 5);
        $this->assertSame('match', $r['level']);
        $this->assertGreaterThanOrEqual(80, $r['candidates'][0]['score']);
        $this->assertSame('otherYear', $r['candidates'][0]['signals']['date']);
    }

    public function testSamePlateAndPlaceOnADifferentDayStillCannotBeAMatch(): void
    {
        // A08: ten sam pojazd w tym samym miejscu 11 dni później to inne zgłoszenie – poprawka roku tego nie psuje
        $cands = [self::cand('1', 'XO8T372', '2026-03-17T08:05:00', 'Przełajowa 14, Poznań')];
        $r = matchCase(self::case(['plate' => 'XO8T372', 'date' => '2026-03-06', 'time' => '08:10', 'address' => ['street' => 'Przełajowej', 'number' => '14']]), $cands, 5);
        $this->assertNotSame('match', $r['level']);
    }

    public function testDateWithoutYearComparesMonthAndDay(): void
    {
        $this->assertSame([1.0, 'exact'], dateSimilarity('--09-16', '2025-09-16T16:09:00'));
        $this->assertSame([1.0, 'exact'], dateSimilarity('--09-16', '2026-09-16T16:09:00'));
        $this->assertSame('none', dateSimilarity('--09-16', '2025-11-02T10:00:00')[1]);
    }

    public function testTimeSimilarity(): void
    {
        $this->assertSame([1.0, 'exact'], timeSimilarity('08:10', '2026-03-06T08:12:00'));
        $this->assertSame([0.6, 'near'], timeSimilarity('08:10', '2026-03-06T08:30:00'));
        $this->assertSame('differs', timeSimilarity('08:10', '2026-03-06T14:00:00')[1]);
        $this->assertSame('none', timeSimilarity(null, '2026-03-06T08:10:00')[1]);
    }

    public function testAddressSimilarityHandlesInflectionAndTypos(): void
    {
        $addr = 'Przełajowa 14, 61-000 Poznań';
        $this->assertSame('streetAndNumber', addressSimilarity(['street' => 'Przełajowej', 'number' => '14'], $addr)[1], 'odmiana');
        $this->assertSame('street', addressSimilarity(['street' => 'Przełajowej', 'number' => '99'], $addr)[1], 'inny numer');
        $this->assertSame('street', addressSimilarity(['street' => 'Przelajowa'], $addr)[1], 'bez polskich znaków');
        $this->assertSame('none', addressSimilarity(['street' => 'Lubomirskiego'], $addr)[1]);
        $this->assertSame('city', addressSimilarity(['city' => 'Poznań'], $addr)[1], 'tylko miasto');
        $this->assertSame('streetAndNumber', addressSimilarity(['street' => 'ul. Mordechaja Anielewicza', 'number' => '45'], 'Mordechaja Anielewicza 45, Warszawa')[1]);
        $this->assertGreaterThan(0.8, addressSimilarity(['street' => 'Bernarda Pretficza', 'number' => '37'], 'Bernarda Pretficza 37, Wrocław')[0]);
    }

    public function testUdNumberIsDecisiveButOnlyForOwnPrefix(): void
    {
        $cands = [self::cand('1', 'XX1111', '2026-01-01T10:00:00', 'Inna 1'), self::cand('7', 'XB0558Q', '2026-04-29T10:00:00', 'Gorzowska 1')];
        $own = matchCase(self::case(['ids' => ['udNumber' => 'UD/5/7'], 'plate' => 'ZZZ999']), $cands, 5);
        $this->assertSame('match', $own['level']);
        $this->assertSame('7', $own['candidates'][0]['appId']);
        $this->assertSame(100, $own['candidates'][0]['score']);

        $foreign = matchCase(self::case(['ids' => ['udNumber' => 'UD/777/7'], 'plate' => 'XB0558Q', 'date' => '2026-04-29']), $cands, 5);
        $this->assertArrayNotHasKey('ud', $foreign['candidates'][0]['signals'], 'numer UD innego użytkownika nie jest kluczem');
        $this->assertSame('7', $foreign['candidates'][0]['appId'], 'ale nr rej. + data dalej dopasowują');
    }

    public function testExistingCaseNumberMatchesFollowUpLetters(): void
    {
        $cands = [
            self::cand('1', 'AAA1111', '2026-01-01T10:00:00', 'X 1'),
            self::cand('2', 'BBB2222', '2026-02-02T10:00:00', 'Y 2', ['externalId' => 'RSOW 201/26']),
        ];
        $r = matchCase(self::case(['ids' => ['rsow' => '201/26']]), $cands, 5);
        $this->assertSame('match', $r['level']);
        $this->assertSame('2', $r['candidates'][0]['appId']);
        $this->assertSame('exact', $r['candidates'][0]['signals']['externalId']);
    }

    public function testTypoInPlateAndDateOffByOneStillFindsReport(): void
    {
        $cands = [
            self::cand('1', 'XB8684Z', '2026-05-13T10:00:00', 'Mieszka I 5, Gorzów Wielkopolski'),
            self::cand('2', 'XK4424N', '2026-02-06T07:55:00', 'Żółkiewskiego 17, Kraków'),
        ];
        $r = matchCase(self::case([
            'plate' => 'XB86847', 'date' => '2026-05-14', 'address' => ['street' => 'Mieszka I', 'city' => 'Gorzów Wlkp.'],
        ]), $cands, 5);
        $this->assertNotSame('none', $r['level']);
        $this->assertSame('1', $r['candidates'][0]['appId']);
        $this->assertSame('fuzzy1', $r['candidates'][0]['signals']['plate']);
    }

    public function testSamePlateOnDifferentDaysIsResolvedByDate(): void
    {
        // A08: ten sam Fiat XO8T372 w dwóch pismach (6 i 17 marca)
        $cands = [
            self::cand('a', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań'),
            self::cand('b', 'XO8T372', '2026-03-17T08:05:00', 'Przełajowa 14, Poznań'),
        ];
        $first = matchCase(self::case(['plate' => 'XO8T372', 'date' => '2026-03-06', 'time' => '08:10', 'address' => ['street' => 'Przełajowej', 'number' => '14']]), $cands, 5);
        $second = matchCase(self::case(['plate' => 'XO8T372', 'date' => '2026-03-17', 'time' => '08:05', 'address' => ['street' => 'Przełajowej', 'number' => '14']]), $cands, 5);
        $this->assertSame('match', $first['level']);
        $this->assertSame('a', $first['candidates'][0]['appId']);
        $this->assertSame('match', $second['level']);
        $this->assertSame('b', $second['candidates'][0]['appId']);
    }

    public function testIdenticalCandidatesAreATieNotAnAutoMatch(): void
    {
        $cands = [
            self::cand('a', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań'),
            self::cand('b', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań'),
        ];
        $r = matchCase(self::case(['plate' => 'XO8T372', 'date' => '2026-03-06', 'address' => ['street' => 'Przełajowa', 'number' => '14']]), $cands, 5);
        $this->assertSame('candidates', $r['level'], 'remis – użytkownik wybiera');
        $this->assertCount(2, $r['candidates']);
    }

    public function testDifferentVehicleCannotBeAMatch(): void
    {
        $cands = [self::cand('1', 'XK4424N', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań')];
        $r = matchCase(self::case(['plate' => 'XO8T372', 'date' => '2026-03-06', 'address' => ['street' => 'Przełajowa', 'number' => '14']]), $cands, 5);
        $this->assertNotSame('match', $r['level']);
    }

    public function testDifferentDayCapsScore(): void
    {
        $cands = [self::cand('1', 'XO8T372', '2026-07-01T08:10:00', 'Przełajowa 14, Poznań')];
        $r = matchCase(self::case(['plate' => 'XO8T372', 'date' => '2026-03-06', 'address' => ['street' => 'Przełajowa', 'number' => '14']]), $cands, 5);
        $this->assertNotSame('match', $r['level'], 'ta sama tablica, ale inny dzień');
    }

    public function testPlateOnlyLetterWithUniquePlateIsOfferedAsCandidate(): void
    {
        // A05: IVECO XWE 7332F – pismo bez daty i adresu
        $cands = [self::cand('1', 'XWE7332F', '2026-03-06T08:10:00', 'X 1'), self::cand('2', 'XK4424N', '2026-02-06T07:55:00', 'Y 2')];
        $r = matchCase(self::case(['plate' => 'XWE 7332F']), $cands, 5);
        $this->assertSame('candidates', $r['level']);
        $this->assertSame('1', $r['candidates'][0]['appId']);
        $this->assertTrue($r['candidates'][0]['signals']['uniquePlate']);
    }

    public function testLetterWithoutIdentifiersMatchesNothing(): void
    {
        $r = matchCase(self::case(), [self::cand('1', 'XK4424N', '2026-02-06T07:55:00', 'Y 2')], 5);
        $this->assertSame('none', $r['level']);
        $this->assertSame([], $r['candidates']);
    }

    public function testNoCandidates(): void
    {
        $this->assertSame('none', matchCase(self::case(['plate' => 'XO8T372']), [], 5)['level']);
    }

    public function testMakeAndCityHelpWhenPlateIsRedacted(): void
    {
        // A09: nr rej. i ulica zamazane – zostaje data, miasto i marka
        $cands = [
            self::cand('t', 'DW1234A', '2026-06-03T09:00:00', 'Legnicka 1, Wrocław', ['vehicle' => 'Toyota Yaris']),
            self::cand('o', 'DW9999B', '2026-06-03T09:30:00', 'Legnicka 9, Wrocław', ['vehicle' => 'Opel Astra']),
        ];
        $r = matchCase(self::case(['date' => '2026-06-03', 'address' => ['city' => 'Wrocław'], 'vehicleMake' => 'TOYOTA']), $cands, 5);
        $this->assertSame('t', $r['candidates'][0]['appId']);
        $this->assertGreaterThan($r['candidates'][1]['score'] ?? 0, $r['candidates'][0]['score']);
    }

    public function testAtMostThreeCandidatesAreListed(): void
    {
        $cands = [];
        for ($i = 0; $i < 6; $i++) $cands[] = self::cand((string)$i, 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań');
        $r = matchCase(self::case(['plate' => 'XO8T372', 'date' => '2026-03-06']), $cands, 5);
        $this->assertCount(3, $r['candidates']);
    }
}
