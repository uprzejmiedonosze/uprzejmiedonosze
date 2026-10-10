<?php

namespace UprzejmieDonosze\Tests\Letter;

require_once __DIR__ . '/../../export/inc/LetterProposal.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function letter\buildNote;
use function letter\proposeEdit;
use function letter\proposeExternalId;
use function letter\proposeStatus;

/** Propozycja edycji: outcome → status (z przejściami z statuses.json), numer sprawy, notatka. Czyste funkcje. */
class LetterProposalTest extends TestCase
{
    /** @return array<string,array> prawdziwa definicja statusów (źródło prawdy) */
    private static function statuses(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../src/api/config/statuses.json'), true);
    }

    private static function cand(array $over = []): array
    {
        return $over + ['id' => 'a', 'number' => 'UD/5/1', 'status' => 'confirmed-waiting', 'externalId' => '', 'privateComment' => ''];
    }

    private static function case(string $outcome, array $over = []): array
    {
        return $over + ['ids' => [], 'outcome' => $outcome, 'reason' => null];
    }

    private const LETTER = ['kind' => 'single', 'authority' => 'Komisariat Policji II w Krakowie', 'date' => '2026-09-15'];

    #[DataProvider('outcomes')]
    public function testOutcomeMapsToStatusFromSentReport(string $outcome, ?string $expected): void
    {
        $r = proposeStatus($outcome, 'confirmed-waiting', self::statuses());
        $this->assertSame($expected, $r['status']);
        $this->assertNull($r['blocked']);
    }

    public static function outcomes(): array
    {
        return [
            'mandat' => ['fined', 'confirmed-fined'],
            'pouczenie' => ['instructed', 'confirmed-instructed'],
            'brak znamion' => ['no_offence', 'confirmed-ignored'],
            'niewykrycie sprawcy' => ['perpetrator_unknown', 'confirmed-ignored'],
            'zmarł / ustanie karalności' => ['dismissed_other', 'confirmed-ignored'],
            'w toku' => ['in_progress', 'confirmed-sm'],
            'zakończone bez wyniku' => ['closed_unspecified', null],
            'nieznane' => ['unknown', null],
        ];
    }

    public function testStatusUnchangedWhenAlreadySet(): void
    {
        $this->assertNull(proposeStatus('fined', 'confirmed-fined', self::statuses())['status']);
    }

    public function testStatusChangeBlockedWhenTransitionNotAllowed(): void
    {
        $r = proposeStatus('fined', 'confirmed', self::statuses()); // „Nowe” → mandat nie jest dozwolone
        $this->assertNull($r['status']);
        $this->assertSame('confirmed', $r['blocked']['from']);
        $this->assertSame('confirmed-fined', $r['blocked']['to']);
        $this->assertStringContainsString('nie jest dozwolona', $r['blocked']['reason']);
    }

    public function testInProgressLetterNeverDowngradesAFinalStatus(): void
    {
        foreach (['confirmed-fined', 'confirmed-instructed', 'confirmed-ignored', 'confirmed-complaint'] as $final) {
            $r = proposeStatus('in_progress', $final, self::statuses());
            $this->assertNull($r['status'], $final);
            $this->assertNull($r['blocked'], $final);
        }
    }

    public function testExternalIdPreference(): void
    {
        $this->assertSame('RSOW 101/26', proposeExternalId(['rsow' => '101/26', 'ersow' => '1/26', 'caseMark' => 'KPI-1/2/26', 'ldz' => '9']));
        $this->assertSame('eRSOW 12/2026', proposeExternalId(['ersow' => '12/2026', 'caseMark' => 'KPI-1/2/26']));
        $this->assertSame('KPI-10/999/26', proposeExternalId(['caseMark' => 'KPI-10/999/26', 'ldz' => '9']));
        $this->assertSame('L.dz. 4567/26', proposeExternalId(['ldz' => '4567/26']));
        $this->assertNull(proposeExternalId(['mandateNo' => 'DJ 1234567']), 'numer mandatu to nie numer sprawy');
        $this->assertNull(proposeExternalId([]));
    }

    public function testProposalSetsStatusExternalIdAndAppendsNote(): void
    {
        $p = proposeEdit(self::case('fined', ['ids' => ['rsow' => '201/26', 'mandateNo' => 'DJ 1234567']]), self::LETTER, self::cand(), self::statuses());
        $this->assertSame('confirmed-fined', $p['set']['status']);
        $this->assertSame('RSOW 201/26', $p['set']['externalId']);
        $this->assertStringStartsWith('[Pismo z 15.09.2026] Komisariat Policji II w Krakowie: ukarano mandatem', $p['append']['privateComment']);
        $this->assertStringContainsString('sprawa RSOW 201/26', $p['append']['privateComment']);
        $this->assertStringContainsString('mandat DJ 1234567', $p['append']['privateComment']);
        $this->assertNull($p['statusBlocked']);
        $this->assertSame([], $p['conflicts']);
        $this->assertFalse($p['noop']);
    }

    public function testRefusalReasonGoesToTheNote(): void
    {
        $p = proposeEdit(self::case('dismissed_other', ['reason' => 'obwiniony zmarł']), self::LETTER, self::cand(), self::statuses());
        $this->assertSame('confirmed-ignored', $p['set']['status']);
        $this->assertStringContainsString('(obwiniony zmarł)', $p['append']['privateComment']);
    }

    public function testDifferentExistingCaseNumberIsAConflictNotAnOverwrite(): void
    {
        $p = proposeEdit(self::case('fined', ['ids' => ['rsow' => '201/26']]), self::LETTER, self::cand(['externalId' => 'RSOW 999/26']), self::statuses());
        $this->assertNull($p['set']['externalId']);
        $this->assertSame([['field' => 'externalId', 'current' => 'RSOW 999/26', 'proposed' => 'RSOW 201/26']], $p['conflicts']);
    }

    public function testSameExistingCaseNumberIsNotProposedAgain(): void
    {
        $p = proposeEdit(self::case('fined', ['ids' => ['rsow' => '201/26']]), self::LETTER, self::cand(['externalId' => 'rsow 201/26']), self::statuses());
        $this->assertNull($p['set']['externalId']);
        $this->assertSame([], $p['conflicts']);
    }

    public function testNoteIsNotDuplicated(): void
    {
        $case = self::case('instructed');
        $note = buildNote(self::LETTER, $case, null);
        $p = proposeEdit($case, self::LETTER, self::cand(['status' => 'confirmed-instructed', 'privateComment' => "moja uwaga\n$note"]), self::statuses());
        $this->assertNull($p['append']['privateComment']);
        $this->assertTrue($p['noop'], 'status, numer i notatka już są');
    }

    public function testBlockedStatusStillOffersCaseNumberAndNote(): void
    {
        $p = proposeEdit(self::case('fined', ['ids' => ['rsow' => '1/26']]), self::LETTER, self::cand(['status' => 'confirmed']), self::statuses());
        $this->assertNull($p['set']['status']);
        $this->assertNotNull($p['statusBlocked']);
        $this->assertSame('RSOW 1/26', $p['set']['externalId']);
        $this->assertNotNull($p['append']['privateComment']);
    }

    public function testNoteWithoutLetterDateOrAuthority(): void
    {
        $this->assertSame('[Pismo] pouczenie', buildNote(['kind' => 'single', 'authority' => null, 'date' => null], self::case('instructed'), null));
    }
}
