<?php

namespace UprzejmieDonosze\Tests\Store;

use app\Application;
use user\User;
use UprzejmieDonosze\Tests\DatabaseTestCase;

/**
 * \user\apps() — the paged, searchable list shared by /app/list, REST
 * /api/rest/user/apps and the MCP list_reports tool.
 */
class UserAppsTest extends DatabaseTestCase
{
    private const EMAIL = 'apps-list@example.com';

    private const APP_JSON_TEMPLATE = '{"date":"2019-03-31T13:06:23","id":"x","added":"2019-04-14T13:22:48","user":{"email":"apps-list@example.com","name":"Ud Developer","number":2,"exposeData":false,"msisdn":"","address":"Rynek 99-120, Piątek"},"status":"confirmed","category":8,"statements":{"witness":false},"statusHistory":{},"carInfo":{"plateId":"ZS2450C"},"address":{"address":"aleja Papieża Jana Pawła II 36, Szczecin","city":"Szczecin"},"smCity":"szczecin","userComment":"","number":"UD/2/2","comments":[],"extensions":[],"seq":2,"version":"2.3.0"}';

    private function makeApp(string $id, int $seq, array $overrides = []): Application
    {
        $data = array_replace_recursive(json_decode(self::APP_JSON_TEMPLATE, true), [
            'id' => $id,
            'seq' => $seq,
            'number' => "UD/2/$seq",
        ], $overrides);
        $app = Application::withJson(json_encode($data), self::EMAIL);
        \app\save($app);
        return $app;
    }

    private function user(): User
    {
        $user = new User();
        $user->data->email = self::EMAIL;
        return $user;
    }

    private function ids(array $apps): array
    {
        return array_map(fn($app) => $app->id, $apps);
    }

    public function testPagesNewestFirst(): void
    {
        foreach (range(1, 5) as $seq)
            $this->makeApp("page-$seq", $seq);

        self::assertSame(['page-5', 'page-4'], $this->ids(\user\apps($this->user(), limit: 2)));
        self::assertSame(['page-3', 'page-2'], $this->ids(\user\apps($this->user(), limit: 2, offset: 2)));
    }

    public function testEmptySearchVariantsReturnEverything(): void
    {
        $this->makeApp('empty-1', 1);
        $this->makeApp('empty-2', 2);

        $expected = $this->ids(\user\apps($this->user()));
        self::assertCount(2, $expected);
        foreach (['', '%', 'all', '  '] as $search)
            self::assertSame($expected, $this->ids(\user\apps($this->user(), search: $search)), "search '$search'");
    }

    public function testSearchByPlateNumberAndCaseInsensitivePolishAddress(): void
    {
        $this->makeApp('search-plate', 1, ['carInfo' => ['plateId' => 'WA12345']]);
        $this->makeApp('search-addr', 2, ['address' => ['address' => 'ul. Łódzka 5, Kraków']]);
        $this->makeApp('search-other', 3);

        self::assertSame(['search-plate'], $this->ids(\user\apps($this->user(), search: 'wa123')));
        self::assertSame(['search-addr'], $this->ids(\user\apps($this->user(), search: 'ŁÓDZKA')));
        self::assertSame(['search-other'], $this->ids(\user\apps($this->user(), search: 'UD/2/3')));
    }

    public function testSearchMatchesEncryptedComment(): void
    {
        $_SESSION['user_id'] = 'apps-list-uid';
        $this->makeApp('search-enc', 1, ['userComment' => 'Zastawił wjazd do garażu']);
        $this->makeApp('search-enc-other', 2);

        $raw = \store\get('applications', 'search-enc');
        self::assertStringNotContainsString('garażu', $raw, 'fixture must actually be stored encrypted');

        self::assertSame(['search-enc'], $this->ids(\user\apps($this->user(), search: 'GARAŻU')));
    }

    public function testSearchPagesOverMatchesOnly(): void
    {
        foreach (range(1, 6) as $seq)
            $this->makeApp("match-$seq", $seq, ['carInfo' => ['plateId' => $seq % 2 ? 'ODD1' : 'EVEN2']]);

        self::assertSame(['match-5', 'match-3'], $this->ids(\user\apps($this->user(), search: 'odd', limit: 2)));
        self::assertSame(['match-1'], $this->ids(\user\apps($this->user(), search: 'odd', limit: 2, offset: 2)));
    }

    public function testSearchSkipsDraftWithoutDate(): void
    {
        $this->makeApp('nodate', 1, ['status' => 'draft', 'date' => null]);
        $this->makeApp('dated', 2);

        self::assertSame(['dated'], $this->ids(\user\apps($this->user(), status: 'allWithDrafts', search: 'UD/2/2')));
    }

    public function testSendableStatusGroup(): void
    {
        $this->makeApp('st-confirmed', 1, ['status' => 'confirmed']);
        $this->makeApp('st-failed', 2, ['status' => 'sending-failed']);
        $this->makeApp('st-waiting', 3, ['status' => 'confirmed-waiting']);

        self::assertSame(['st-failed', 'st-confirmed'], $this->ids(\user\apps($this->user(), status: 'sendable')));
    }

    public function testQueryParamsClampLimit(): void
    {
        self::assertSame(\user\APPS_PAGE_SIZE, \user\appsQueryFromParams([])['limit']);
        self::assertSame(\user\APPS_PAGE_SIZE, \user\appsQueryFromParams(['limit' => 0])['limit']);
        self::assertSame(\user\APPS_PAGE_SIZE, \user\appsQueryFromParams(['limit' => -5])['limit']);
        self::assertSame(\user\APPS_PAGE_MAX, \user\appsQueryFromParams(['limit' => 100000])['limit']);
        self::assertSame(0, \user\appsQueryFromParams(['offset' => -3])['offset']);
        self::assertSame('abc', \user\appsQueryFromParams(['q' => 'abc'])['search']);
    }
}
