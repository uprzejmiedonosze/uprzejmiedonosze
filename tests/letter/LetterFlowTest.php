<?php

namespace UprzejmieDonosze\Tests\Letter;

require_once __DIR__ . '/../../export/inc/LetterProposal.php';

use app\Application;
use cache\Type;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use quota\QuotaExceededException;
use UprzejmieDonosze\Tests\DatabaseTestCase;
use user\User;
use vision\VisionClient;
use vision\VisionException;

use function letter\analyzeForUser;
use function letter\applyLetter;

/** Przepływ z bazą: dopasowanie do zgłoszeń użytkownika, kredyty (\quota) i zatwierdzenie propozycji w blokadzie. */
class LetterFlowTest extends DatabaseTestCase
{
    private mixed $patronsBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patronsBefore = \cache\get(Type::Patronite, '');
        // niepusty cache patronów = test offline (jak PhotoQuotaTest)
        \cache\set(Type::Patronite, '', ['someone-else@example.com' => ['amount' => 5, 'note' => '', 'active' => true]], 0, 60);
    }

    protected function tearDown(): void
    {
        VisionClient::set(null);
        \cache\delete(Type::Patronite, '');
        if ($this->patronsBefore) \cache\set(Type::Patronite, '', $this->patronsBefore, 0, 60);
        parent::tearDown();
    }

    private function savedUser(string $email = 'letter-flow-test@example.com'): User
    {
        $_SESSION['user_email'] = $email;
        $_SESSION['user_id'] = 'phpunit-user-id';
        $user = new User();
        $user->email = $email;
        $user->data->email = $email;
        $user->data->name = 'Test User';
        $user->data->address = 'Testowa 1, Szczecin';
        \user\save($user);
        return $user;
    }

    private function sentApp(User $user, string $status, string $plate, string $date, string $address): Application
    {
        $app = Application::withUser($user);
        $app->statusHistory = [];
        $app->comments = [];
        $app->extensions = [];
        $app->date = $date;
        $app->carInfo = new \JSONObject();
        $app->carInfo->plateId = $plate;
        $app->address = new \JSONObject();
        $app->address->address = $address;
        $app->status = $status;
        return \app\save($app);
    }

    private static function image(): string
    {
        return random_bytes(64); // unikalny sha1 – własny klucz cache i kredytu w każdym teście
    }

    private static function fakeLetter(array $cases, string $transcript = 'treść pisma'): ClientFake
    {
        $json = json_encode([
            'transcript' => $transcript,
            'letter' => ['kind' => 'single', 'authority' => 'Komisariat Policji Poznań-Północ', 'date' => '2026-05-07'],
            'cases' => array_map(fn ($c) => $c + [
                'ids' => ['udNumber' => null, 'rsow' => null, 'ersow' => null, 'ldz' => null, 'caseMark' => null, 'mandateNo' => null],
                'vehicleMake' => null, 'time' => null, 'address' => ['street' => null, 'number' => null, 'city' => null],
                'reason' => null, 'articles' => [], 'confidence' => 0.9,
            ], $cases),
        ]);
        return new ClientFake([CreateResponse::fake([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $json], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ])]);
    }

    // --- analyzeForUser ---------------------------------------------------------

    public function testLetterIsMatchedToTheUsersReportWithAProposal(): void
    {
        $user = $this->savedUser();
        $mine = $this->sentApp($user, 'confirmed-waiting', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań');
        $this->sentApp($user, 'confirmed-waiting', 'XK4424N', '2026-02-06T07:55:00', 'Żółkiewskiego 17, Kraków');
        VisionClient::set(self::fakeLetter([[
            'plate' => 'XO8T372', 'date' => '2026-03-06', 'time' => '08:10', 'outcome' => 'instructed',
            'address' => ['street' => 'Przełajowej', 'number' => '14', 'city' => 'Poznań'],
        ]]));

        $r = analyzeForUser($user, self::image());

        $this->assertCount(1, $r['cases']);
        $case = $r['cases'][0];
        $this->assertSame('match', $case['match']['level']);
        $this->assertSame($mine->id, $case['match']['appId']);
        $this->assertSame($mine->id, $case['candidates'][0]['appId']);
        $this->assertSame('confirmed-instructed', $case['candidates'][0]['proposal']['set']['status']);
        $this->assertStringContainsString('pouczenie', $case['candidates'][0]['proposal']['append']['privateComment']);
        $this->assertSame('XO8T372', $case['case']['plate']);
        $this->assertArrayNotHasKey('transcript', $r, 'transkrypt (dane osobowe) nie wychodzi do klienta');
        $this->assertSame(1, $r['usage']['calls']);
    }

    public function testLetterOfAnotherUsersReportIsNotMatched(): void
    {
        $owner = $this->savedUser('letter-owner@example.com');
        $this->sentApp($owner, 'confirmed-waiting', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań');
        $other = $this->savedUser('letter-other@example.com');
        VisionClient::set(self::fakeLetter([['plate' => 'XO8T372', 'date' => '2026-03-06', 'outcome' => 'instructed']]));

        $r = analyzeForUser($other, self::image());
        $this->assertSame('none', $r['cases'][0]['match']['level']);
        $this->assertSame([], $r['cases'][0]['candidates']);
    }

    public function testAnalysisChargesOneCreditAndRepeatIsFreeAndCached(): void
    {
        $user = $this->savedUser();
        $this->sentApp($user, 'confirmed-waiting', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań');
        VisionClient::set(self::fakeLetter([['plate' => 'XO8T372', 'date' => '2026-03-06', 'outcome' => 'fined']]));
        $image = self::image();

        $before = \quota\status($user)['used'];
        $first = analyzeForUser($user, $image);
        $this->assertSame($before + 1, \quota\status($user)['used'], '1 kredyt na zdjęcie');
        $this->assertNotNull($first['usage']);

        VisionClient::set(null); // drugi raz model nie może być wołany
        $second = analyzeForUser($user, $image);
        $this->assertNull($second['usage'], 'trafienie w cache');
        $this->assertSame($before + 1, \quota\status($user)['used'], 'ponowne wgranie nic nie kosztuje');
        $this->assertSame($first['cases'][0]['match'], $second['cases'][0]['match']);
    }

    public function testCreditsAreReleasedWhenTheModelFails(): void
    {
        $user = $this->savedUser();
        $bad = CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'brak JSON'], 'finish_reason' => 'stop']]]);
        VisionClient::set(new ClientFake([$bad, $bad]));

        $before = \quota\status($user)['used'];
        try {
            analyzeForUser($user, self::image());
            $this->fail('oczekiwano VisionException');
        } catch (VisionException) {
        }
        $this->assertSame($before, \quota\status($user)['used'], 'awaria modelu nie zjada limitu');
    }

    public function testExhaustedQuotaStopsBeforeCallingTheModel(): void
    {
        $user = $this->savedUser();
        $stmt = \store\prepare('INSERT INTO photo_usage (user_email, sha1, created_at) VALUES (:e, :h, :t)');
        for ($i = 0; $i < PHOTO_QUOTA_FREE; $i++) $stmt->execute([':e' => $user->getEmail(), ':h' => sha1("fill$i"), ':t' => time()]);
        VisionClient::set(new ClientFake([])); // każde wywołanie modelu rzuciłoby wyjątek

        $this->expectException(QuotaExceededException::class);
        analyzeForUser($user, self::image());
    }

    // --- applyLetter ---------------------------------------------------------------

    public function testApplySetsStatusCaseNumberAndAppendsNote(): void
    {
        $user = $this->savedUser();
        $app = $this->sentApp($user, 'confirmed-waiting', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań');
        $app->privateComment = 'moja uwaga';
        \app\save($app);

        $saved = applyLetter($app->id, [
            'status' => 'confirmed-fined', 'externalId' => 'RSOW 201/26',
            'appendNote' => '[Pismo z 15.09.2026] mandat', 'letterSummary' => 'Mandat (pismo KMP)',
        ], $user);

        $fresh = \app\get($app->id);
        $this->assertSame('confirmed-fined', $fresh->status);
        $this->assertSame('RSOW 201/26', $fresh->externalId);
        $this->assertSame("moja uwaga\n[Pismo z 15.09.2026] mandat", $fresh->privateComment);
        $this->assertSame('confirmed-fined', $saved->status);
        $this->assertCount(1, $fresh->statusHistory);
        $comment = array_values((array)$fresh->comments)[0];
        $this->assertSame('letter', $comment->source);
        $this->assertSame('Mandat (pismo KMP)', $comment->comment);
        $this->assertSame('confirmed-fined', $comment->status);
    }

    public function testApplyIsIdempotentForTheNote(): void
    {
        $user = $this->savedUser();
        $app = $this->sentApp($user, 'confirmed-waiting', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań');
        $body = ['status' => 'confirmed-instructed', 'appendNote' => '[Pismo] pouczenie'];

        applyLetter($app->id, $body, $user);
        applyLetter($app->id, $body, $user);

        $fresh = \app\get($app->id);
        $this->assertSame('[Pismo] pouczenie', $fresh->privateComment, 'bez duplikatu');
        $this->assertCount(1, $fresh->statusHistory, 'ten sam status – bez drugiego wpisu historii');
    }

    public function testApplyOnlyCaseNumberAndNoteWhenStatusIsNotSent(): void
    {
        $user = $this->savedUser();
        $app = $this->sentApp($user, 'confirmed', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań');
        applyLetter($app->id, ['externalId' => 'RSOW 1/26', 'appendNote' => 'notatka'], $user);
        $fresh = \app\get($app->id);
        $this->assertSame('confirmed', $fresh->status);
        $this->assertSame('RSOW 1/26', $fresh->externalId);
    }

    public function testApplyRejectsDisallowedTransitionWithoutSavingAnything(): void
    {
        $user = $this->savedUser();
        $app = $this->sentApp($user, 'confirmed', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań');
        try {
            applyLetter($app->id, ['status' => 'confirmed-fined', 'externalId' => 'RSOW 1/26', 'appendNote' => 'x'], $user);
            $this->fail('oczekiwano 409');
        } catch (\Exception $e) {
            $this->assertSame(409, $e->getCode());
        }
        $fresh = \app\get($app->id);
        $this->assertSame('confirmed', $fresh->status);
        $this->assertSame('', $fresh->externalId, 'zmiana jest atomowa – numer sprawy też się nie zapisał');
        $this->assertSame('', $fresh->privateComment);
    }

    public function testApplyRejectsAnotherUsersReport(): void
    {
        $owner = $this->savedUser('letter-apply-owner@example.com');
        $app = $this->sentApp($owner, 'confirmed-waiting', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań');
        $other = $this->savedUser('letter-apply-other@example.com');
        try {
            applyLetter($app->id, ['appendNote' => 'x'], $other);
            $this->fail('oczekiwano 403');
        } catch (\Exception $e) {
            $this->assertSame(403, $e->getCode());
        }
        $this->assertSame('', \app\get($app->id)->privateComment);
    }

    public function testApplyValidatesInput(): void
    {
        $user = $this->savedUser();
        $app = $this->sentApp($user, 'confirmed-waiting', 'XO8T372', '2026-03-06T08:10:00', 'Przełajowa 14, Poznań');
        foreach ([
            ['status' => 'no-such-status'],
            ['status' => ['x']],
            ['externalId' => str_repeat('1', 101)],
            ['appendNote' => str_repeat('x', 2001)],
            ['externalId' => 123],
        ] as $body) {
            try {
                applyLetter($app->id, $body, $user);
                $this->fail('oczekiwano InvalidArgumentException dla ' . json_encode($body));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
