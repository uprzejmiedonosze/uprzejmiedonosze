<?php

namespace UprzejmieDonosze\Tests\Letter;

require_once __DIR__ . '/../../export/inc/integrations/LetterVision.php';

use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\TestCase;
use vision\VisionClient;
use vision\VisionException;

use function letter\analyzeLetter;
use function letter\decodeUnicodeEscapes;
use function letter\extractCaseIds;
use function letter\normalizeLetter;
use function letter\resolveYear;
use function letter\validateLetter;

/** Ekstrakcja z pism: regexy numerów spraw na prawdziwych transkryptach, normalizacja i analyzeLetter() z fałszywym klientem OpenAI. */
class LetterVisionTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/letters';

    private mixed $pricingBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingBefore = $GLOBALS['MODEL_PRICING'] ?? null;
        $GLOBALS['MODEL_PRICING'] = [OPENAI_LETTER_MODEL => ['prompt' => 0.25, 'completion' => 2.00]]; // visionCostUsd() czyta globalny cennik
    }

    protected function tearDown(): void
    {
        VisionClient::set(null);
        $GLOBALS['MODEL_PRICING'] = $this->pricingBefore;
        parent::tearDown();
    }

    private static function transcript(string $name): string
    {
        return file_get_contents(self::FIXTURES . "/$name.txt");
    }

    /** @return array<string,array> */
    private static function expected(): array
    {
        return json_decode(file_get_contents(self::FIXTURES . '/expected.json'), true);
    }

    private static function modelAnswer(string $transcript, array $expected): array
    {
        return [
            'transcript' => $transcript,
            'letter' => ['kind' => $expected['kind'], 'authority' => $expected['authority'] ?? null, 'date' => $expected['letterDate'] ?? null],
            'cases' => array_map(fn ($c) => [
                'ids' => $c['ids'] + ['udNumber' => null, 'rsow' => null, 'ersow' => null, 'ldz' => null, 'caseMark' => null, 'mandateNo' => null],
                'plate' => $c['plate'] ?? null,
                'vehicleMake' => null,
                'date' => $c['date'] ?? null,
                'time' => $c['time'] ?? null,
                'address' => ['street' => $c['street'] ?? null, 'number' => null, 'city' => $c['city'] ?? null],
                'outcome' => $c['outcome'],
                'reason' => null,
                'articles' => [],
                'confidence' => 0.9,
            ], $expected['cases']),
        ];
    }

    // --- regexy numerów spraw -------------------------------------------------

    public function testBulkLetterFromGorzowYieldsEveryUdAndRsowNumber(): void
    {
        $ids = extractCaseIds(self::transcript('letter-04'));
        $this->assertCount(11, $ids['udNumber']);
        $this->assertSame('UD/777/1', $ids['udNumber'][0]);
        $this->assertSame('UD/777/35', $ids['udNumber'][10]);
        $this->assertCount(11, $ids['rsow']);
        $this->assertSame(['101/26', '102/26', '103/26', '104/26', '105/26', '106/26', '107/26', '108/26', '109/26', '110/26', '111/26'], $ids['rsow']);
        $this->assertSame([], $ids['ersow']);
    }

    public function testRsowNumbersOverStampsAndEmptyLdz(): void
    {
        $ids = extractCaseIds(self::transcript('letter-07'));
        $this->assertSame(['201/26', '202/26'], $ids['rsow']);
        $this->assertSame([], $ids['ldz'], 'puste „L.dz. …” nie jest numerem sprawy');
    }

    public function testMandateNumber(): void
    {
        $ids = extractCaseIds(self::transcript('letter-03'));
        $this->assertSame(['DJ 1234567'], $ids['mandateNo']);
    }

    public function testLettersWithoutNumbersYieldNoIds(): void
    {
        foreach (['letter-01', 'letter-05', 'letter-08'] as $f)
            $this->assertSame([], array_filter(extractCaseIds(self::transcript($f))), $f);
    }

    public function testVariantsOfCaseNumbers(): void
    {
        $ids = extractCaseIds("eRSOW-123/2026, RSOW 7/25 oraz L.dz. 4567/26, KP I-12/999/26 i UD / 5 / 12");
        $this->assertSame(['123/2026'], $ids['ersow']);
        $this->assertSame(['7/25'], $ids['rsow']);
        $this->assertSame(['4567/26'], $ids['ldz']);
        $this->assertSame(['KPI-12/999/26'], $ids['caseMark']);
        $this->assertSame(['UD/5/12'], $ids['udNumber']);
    }

    // --- normalizacja -----------------------------------------------------------

    public function testRealIdsSurviveNormalizationForEveryFixtureWithIds(): void
    {
        $expected = self::expected();
        foreach ($expected as $name => $exp) {
            if (!is_file(self::FIXTURES . "/$name.txt")) continue;
            [$result, $warnings] = normalizeLetter(self::modelAnswer(self::transcript($name), $exp));
            foreach ($exp['cases'] as $i => $c) {
                foreach ($c['ids'] as $k => $v) {
                    $this->assertSame($v, $result['cases'][$i]['ids'][$k], "$name case $i id $k");
                }
            }
            $this->assertSame([], $warnings, $name);
        }
    }

    public function testIdMissingFromTranscriptIsDropped(): void
    {
        $answer = self::modelAnswer('Zawiadamiam, że sprawa o nr rej. XO8T372 zakończona.', ['kind' => 'single', 'cases' => [
            ['plate' => 'XO8T372', 'outcome' => 'fined', 'ids' => ['rsow' => '999/99']],
        ]]);
        [$result, $warnings] = normalizeLetter($answer);
        $this->assertNull($result['cases'][0]['ids']['rsow']);
        $this->assertStringContainsString('rsow=999/99', $warnings[0]);
    }

    public function testSingleIdInTranscriptFillsMissingOne(): void
    {
        $answer = self::modelAnswer("RSOW - 12/26\nUkarano mandatem.", ['kind' => 'single', 'cases' => [['plate' => 'XO8T372', 'outcome' => 'fined', 'ids' => []]]]);
        [$result] = normalizeLetter($answer);
        $this->assertSame('12/26', $result['cases'][0]['ids']['rsow']);
    }

    public function testMalformedIdsAreDroppedEvenWhenPresentInTheTranscript(): void
    {
        // zdarzyło się na prawdziwym skanie: znak sprawy w polu udNumber i zepsuty OCR numeru RSOW
        $answer = self::modelAnswer("RSOWA: 204 I CJI\nKP I - 301/999/26", ['kind' => 'single', 'cases' => [
            ['plate' => 'XB3681N', 'outcome' => 'perpetrator_unknown', 'ids' => ['udNumber' => 'KP I - 301/999/26', 'rsow' => '204']],
        ]]);
        [$result, $warnings] = normalizeLetter($answer);
        $this->assertNull($result['cases'][0]['ids']['udNumber']);
        $this->assertNull($result['cases'][0]['ids']['rsow']);
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('zły format', $warnings[0]);
    }

    public function testSingleTranscriptIdIsNotAssignedWhenLetterHasSeveralCases(): void
    {
        // dwa pisma na zdjęciu, czytelny tylko jeden numer – nie wiadomo, do którego należy
        $answer = self::modelAnswer("KP I-301/999/26\nPismo drugie", ['kind' => 'single', 'cases' => [
            ['plate' => 'AAA1111', 'outcome' => 'fined', 'ids' => []],
            ['plate' => 'BBB2222', 'outcome' => 'fined', 'ids' => []],
        ]]);
        [$result] = normalizeLetter($answer);
        $this->assertNull($result['cases'][0]['ids']['caseMark']);
        $this->assertNull($result['cases'][1]['ids']['caseMark']);
    }

    public function testMandateNumberIsNormalizedAndStillGrounded(): void
    {
        foreach (['DJ nr 1234567', 'seria DJ 1234567', 'seria DJ nr 1234567'] as $fromModel) {
            $answer = self::modelAnswer('mandat karny kredytowany seria DJ nr 1234567.', ['kind' => 'single', 'cases' => [
                ['plate' => 'AAA1111', 'outcome' => 'fined', 'ids' => ['mandateNo' => $fromModel]],
            ]]);
            [$result, $warnings] = normalizeLetter($answer);
            $this->assertSame('DJ 1234567', $result['cases'][0]['ids']['mandateNo'], $fromModel);
            $this->assertSame([], $warnings, $fromModel);
        }
    }

    public function testStatisticalAndGenericLettersHaveNoCases(): void
    {
        $answer = self::modelAnswer('x', ['kind' => 'statistical', 'cases' => [['plate' => 'XO8T372', 'outcome' => 'unknown', 'ids' => []]]]);
        [$result] = normalizeLetter($answer);
        $this->assertSame([], $result['cases']);
    }

    public function testPlateIsNormalizedAndYearResolved(): void
    {
        $answer = self::modelAnswer('x', ['kind' => 'single', 'letterDate' => '2026-10-01', 'cases' => [
            ['plate' => 'xbu er23', 'date' => '--09-16', 'time' => '9:05', 'outcome' => 'perpetrator_unknown', 'ids' => []],
        ]]);
        [$result] = normalizeLetter($answer);
        $this->assertSame('XBUER23', $result['cases'][0]['plate']);
        $this->assertSame('2026-09-16', $result['cases'][0]['date']);
        $this->assertSame('09:05', $result['cases'][0]['time']);
    }

    public function testResolveYear(): void
    {
        $this->assertSame('2026-09-16', resolveYear('--09-16', '2026-10-01'));
        $this->assertSame('2025-12-20', resolveYear('--12-20', '2026-01-05'), 'wykroczenie przed datą pisma, rok wcześniej');
        $this->assertSame('--09-16', resolveYear('--09-16', null));
        $this->assertSame('2026-02-03', resolveYear('2026-02-03', '2026-10-01'));
    }

    // --- walidacja --------------------------------------------------------------

    public function testValidateLetter(): void
    {
        $ok = ['transcript' => 't', 'letter' => ['kind' => 'single', 'date' => '2026-01-01'], 'cases' => [['outcome' => 'fined', 'date' => '--02-03', 'time' => '08:10']]];
        $this->assertSame([], validateLetter($ok));
        $this->assertNotEmpty(validateLetter(['transcript' => 't', 'letter' => ['kind' => 'weird'], 'cases' => []]));
        $this->assertNotEmpty(validateLetter(['transcript' => 't', 'letter' => ['kind' => 'single'], 'cases' => [['outcome' => 'banana']]]));
        $this->assertNotEmpty(validateLetter(['transcript' => 't', 'letter' => ['kind' => 'single'], 'cases' => [['outcome' => 'fined', 'date' => '2026-13-45']]]));
        $this->assertNotEmpty(validateLetter(['letter' => ['kind' => 'single'], 'cases' => []]));
        $this->assertNotEmpty(validateLetter('nie obiekt'));
    }

    // --- analyzeLetter z fałszywym klientem --------------------------------------

    private static function fakeResponse(string $content, int $in = 1000, int $out = 500): CreateResponse
    {
        return CreateResponse::fake([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out],
        ]);
    }

    public function testAnalyzeLetterHappyPath(): void
    {
        $name = 'letter-06';
        $answer = self::modelAnswer(self::transcript($name), self::expected()[$name]);
        VisionClient::set(new ClientFake([self::fakeResponse(json_encode($answer))]));

        $r = analyzeLetter(['jpeg-bytes']);
        $this->assertSame('single', $r['letter']['kind']);
        $this->assertSame('XE943VU', $r['cases'][0]['plate']);
        $this->assertSame('dismissed_other', $r['cases'][0]['outcome']);
        $this->assertSame(1, $r['usage']['calls']);
        $this->assertSame(1000, $r['usage']['prompt_tokens']);
        $this->assertEqualsWithDelta((1000 * 0.25 + 500 * 2.00) / 1_000_000, $r['usage']['cost_usd'], 1e-9);
        $this->assertSame([], $r['warnings']);
    }

    public function testLiteralUnicodeEscapesFromTheModelAreDecoded(): void
    {
        // model zwrócił JSON podwójnie zakodowany: po json_decode w tekście zostaje dosłowne „\\u0143” (aplikacja pokazywała „POZNA\\u0143SKA”)
        $answer = [
            'transcript' => 'Obwiniony zmar\\u0142 przy ul. POZNA\\u0143SKA 21.',
            'letter' => ['kind' => 'single', 'authority' => 'Stra\\u017c Miejska m.st. Warszawy', 'date' => '2026-07-18'],
            'cases' => [[
                'ids' => ['udNumber' => null, 'rsow' => null, 'ersow' => null, 'ldz' => null, 'caseMark' => null, 'mandateNo' => null],
                'plate' => 'XND30380', 'vehicleMake' => null, 'date' => '2025-07-30', 'time' => '19:24',
                'address' => ['street' => 'POZNA\\u0143SKA', 'number' => '21', 'city' => 'Warszawa'],
                'outcome' => 'dismissed_other', 'reason' => 'Obwiniony zmar\\u0142', 'articles' => [], 'confidence' => 0.9,
            ]],
        ];
        $this->assertStringContainsString('\\\\u0143', json_encode($answer), 'sprawdzian: JSON z modelu rzeczywiście ma podwójne kodowanie');
        VisionClient::set(new ClientFake([self::fakeResponse(json_encode($answer))]));

        $r = analyzeLetter(['x']);
        $this->assertSame('Straż Miejska m.st. Warszawy', $r['letter']['authority']);
        $this->assertSame('POZNAŃSKA', $r['cases'][0]['address']['street']);
        $this->assertSame('Obwiniony zmarł', $r['cases'][0]['reason']);
        $this->assertStringContainsString('POZNAŃSKA', $r['transcript']);
        $this->assertStringNotContainsString('\\u', json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    public function testDecodeUnicodeEscapes(): void
    {
        $this->assertSame('zażółć gęślą jaźń', decodeUnicodeEscapes('za\\u017c\\u00f3\\u0142\\u0107 g\\u0119\\u015bl\\u0105 ja\\u017a\\u0144'));
        $this->assertSame('😀', decodeUnicodeEscapes('\\ud83d\\ude00'), 'para surogatów');
        $this->assertSame(['a' => ['Ł'], 'b' => 5, 'c' => null], decodeUnicodeEscapes(['a' => ['\\u0141'], 'b' => 5, 'c' => null]));
        $this->assertSame('już poprawne ąę', decodeUnicodeEscapes('już poprawne ąę'));
        $this->assertSame('ścieżka C:\\temp\\notes', decodeUnicodeEscapes('ścieżka C:\\temp\\notes'), 'inny ukośnik nie jest ruszany');
    }

    public function testAnalyzeLetterRepairsNonJson(): void
    {
        $answer = self::modelAnswer('RSOW 1/26', ['kind' => 'single', 'cases' => [['plate' => 'XO8T372', 'outcome' => 'fined', 'ids' => []]]]);
        VisionClient::set(new ClientFake([self::fakeResponse('Oto wynik: brak JSON'), self::fakeResponse(json_encode($answer))]));
        $r = analyzeLetter(['x']);
        $this->assertSame(2, $r['usage']['calls']);
        $this->assertStringContainsString('naprawa', $r['warnings'][0]);
    }

    public function testAnalyzeLetterRepairsValidationErrorsThenFailsLoudly(): void
    {
        $bad = json_encode(['transcript' => 't', 'letter' => ['kind' => 'nope'], 'cases' => []]);
        VisionClient::set(new ClientFake([self::fakeResponse($bad), self::fakeResponse($bad)]));
        $this->expectException(VisionException::class);
        $this->expectExceptionMessage('walidacja');
        analyzeLetter(['x']);
    }
}
