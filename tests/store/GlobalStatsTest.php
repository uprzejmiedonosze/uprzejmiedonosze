<?php

namespace UprzejmieDonosze\Tests\Store;

use UprzejmieDonosze\Tests\DatabaseTestCase;

/**
 * Covers the Policja/SM split in \global_stats\statsByYear().
 *
 * A report only stores the lowercased unit key, so the recipient has to be
 * re-derived the way SM::resolve() derives it at render time. These tests pin
 * that derivation down: the unit keys come out of the live config rather than
 * being hardcoded, so a renamed unit fails loudly instead of silently moving a
 * report to the wrong side of the bar.
 *
 * The shared test database already holds rows, so every assertion is a delta
 * against a baseline reading rather than an absolute count.
 */
class GlobalStatsTest extends DatabaseTestCase
{
    private const EMAIL = 'stats-split@nieradka.net';

    protected function tearDown(): void
    {
        // statsByYear() writes through to memcached even when asked to skip the
        // read; without this the rolled-back fixture rows would stay visible to
        // whatever runs next.
        \cache\delete(\cache\Type::GlobalStats, 'statsByYear2');
        parent::tearDown();
    }

    /**
     * @return array{smOnly: string, policeOnly: string, both: ?string}
     */
    private function configKeys(): array
    {
        global $SM_ADDRESSES, $POLICE_ADDRESSES;
        $sm = array_keys($SM_ADDRESSES);
        $police = array_keys($POLICE_ADDRESSES);

        $smOnly = array_values(array_diff($sm, $police, ['_nieznane']));
        $policeOnly = array_values(array_diff($police, $sm));
        $both = array_values(array_intersect($sm, $police));

        self::assertNotEmpty($smOnly, 'sm.json has no key of its own');
        self::assertNotEmpty($policeOnly, 'police.json has no key of its own');

        return [
            'smOnly' => $smOnly[0],
            'policeOnly' => $policeOnly[0],
            'both' => $both[0] ?? null,
        ];
    }

    private function insertApp(string $key, ?string $smCity, bool $stopAgresji, string $status): void
    {
        $stmt = \store\store()->prepare(
            'INSERT INTO applications ("key", value, email) VALUES (:k, :v, :e)'
        );
        $stmt->execute([
            ':k' => $key,
            ':v' => json_encode([
                'added' => date('Y-m-d\TH:i:s'),
                'status' => $status,
                'smCity' => $smCity,
                'stopAgresji' => $stopAgresji,
            ], JSON_UNESCAPED_UNICODE),
            ':e' => self::EMAIL,
        ]);
    }

    /**
     * @return array{0: int, 1: int} [smCnt, policeCnt] for the current month
     */
    private function currentMonth(): array
    {
        $month = date('Y-m');
        foreach (\global_stats\statsByYear(useCache: false) as $row) {
            if ($row[0] === $month) {
                return [(int)$row[1], (int)$row[2]];
            }
        }
        return [0, 0];
    }

    public function testRowShapeIsMonthSmPoliceUsers(): void
    {
        // The shared test database holds no report from the last 24 months, so
        // seed one rather than asserting against whatever happens to be there.
        $keys = $this->configKeys();
        $this->insertApp('t-shape-1', $keys['smOnly'], false, 'confirmed-waiting');

        $rows = \global_stats\statsByYear(useCache: false);
        self::assertNotEmpty($rows, 'no stats rows to inspect');
        self::assertCount(4, $rows[0], 'expected [month, sm, police, users]');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $rows[0][0]);
    }

    public function testStopAgresjiCountsAsPolice(): void
    {
        $keys = $this->configKeys();
        [$sm0, $police0] = $this->currentMonth();

        // #stopagresji wins regardless of the key -- even one owned by SM.
        $this->insertApp('t-sa-1', $keys['smOnly'], true, 'confirmed-waiting');

        [$sm1, $police1] = $this->currentMonth();
        self::assertSame($police0 + 1, $police1);
        self::assertSame($sm0, $sm1);
    }

    public function testPoliceOnlyKeyCountsAsPolice(): void
    {
        $keys = $this->configKeys();
        [$sm0, $police0] = $this->currentMonth();

        $this->insertApp('t-pol-1', $keys['policeOnly'], false, 'confirmed-waiting');

        [$sm1, $police1] = $this->currentMonth();
        self::assertSame($police0 + 1, $police1);
        self::assertSame($sm0, $sm1);
    }

    public function testSmKeyWinsOverPoliceForKeysPresentInBoth(): void
    {
        $keys = $this->configKeys();
        if ($keys['both'] === null) {
            self::markTestSkipped('no unit key is present in both sm.json and police.json');
        }
        [$sm0, $police0] = $this->currentMonth();

        $this->insertApp('t-both-1', $keys['both'], false, 'confirmed-waiting');

        [$sm1, $police1] = $this->currentMonth();
        self::assertSame($sm0 + 1, $sm1, 'sm.json must take precedence over police.json');
        self::assertSame($police0, $police1);
    }

    /**
     * SM::resolve() falls back to $SM_ADDRESSES['_nieznane'], which is an SM
     * object -- so an unresolvable report belongs on the SM side of the bar,
     * not in a third bucket that would make the totals drift.
     */
    public function testUnresolvableKeyCountsAsSm(): void
    {
        [$sm0, $police0] = $this->currentMonth();

        $this->insertApp('t-unk-1', '_nieznane', false, 'confirmed-waiting');
        $this->insertApp('t-unk-2', null, false, 'confirmed-waiting');

        [$sm1, $police1] = $this->currentMonth();
        self::assertSame($sm0 + 2, $sm1);
        self::assertSame($police0, $police1);
    }

    public function testKeyMatchingIsCaseInsensitive(): void
    {
        $keys = $this->configKeys();
        [$sm0, $police0] = $this->currentMonth();

        $this->insertApp('t-case-1', mb_strtoupper($keys['policeOnly']), false, 'confirmed-waiting');

        [$sm1, $police1] = $this->currentMonth();
        self::assertSame($police0 + 1, $police1);
        self::assertSame($sm0, $sm1);
    }

    public function testDraftsAndReadyAreExcluded(): void
    {
        $keys = $this->configKeys();
        [$sm0, $police0] = $this->currentMonth();

        $this->insertApp('t-draft-1', $keys['smOnly'], false, 'draft');
        $this->insertApp('t-ready-1', $keys['policeOnly'], false, 'ready');

        [$sm1, $police1] = $this->currentMonth();
        self::assertSame($sm0, $sm1);
        self::assertSame($police0, $police1);
    }

    /**
     * The bar is stacked, so the split must partition exactly the same set the
     * chart used to show as a single column -- no report counted twice, none
     * dropped.
     */
    public function testSplitPreservesTheTotal(): void
    {
        $keys = $this->configKeys();
        [$sm0, $police0] = $this->currentMonth();

        $fixture = [
            ['t-tot-1', $keys['smOnly'], false],
            ['t-tot-2', $keys['policeOnly'], false],
            ['t-tot-3', $keys['smOnly'], true],
            ['t-tot-4', '_nieznane', false],
            ['t-tot-5', null, false],
        ];
        foreach ($fixture as [$key, $city, $stopAgresji]) {
            $this->insertApp($key, $city, $stopAgresji, 'confirmed-waiting');
        }

        [$sm1, $police1] = $this->currentMonth();
        self::assertSame(
            ($sm0 + $police0) + count($fixture),
            $sm1 + $police1,
            'sm + police must equal the number of non-draft reports'
        );
    }
}
