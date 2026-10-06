<?php

namespace UprzejmieDonosze\Tests\Store;

use cache\Type;
use quota\QuotaExceededException;
use user\User;
use UprzejmieDonosze\Tests\DatabaseTestCase;

class PhotoQuotaTest extends DatabaseTestCase
{
    private const EMAIL = 'quota-test@nieradka.net';
    private mixed $patronsBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patronsBefore = \cache\get(Type::Patronite, '');
        $this->setPatron(null);
    }

    protected function tearDown(): void
    {
        \cache\delete(Type::Patronite, '');
        if ($this->patronsBefore) \cache\set(Type::Patronite, '', $this->patronsBefore, 0, 60);
        parent::tearDown();
    }

    private function user(): User
    {
        $user = new User();
        $user->data->email = self::EMAIL; // getEmail() czyta data->email
        return $user;
    }

    /** @param array{amount:int,active:bool}|null $patron */
    private function setPatron(?array $patron): void
    {
        // get() ignores an empty cache and would call Patronite; a non-empty one keeps the test offline.
        $patrons = ['someone-else@example.com' => ['amount' => 5, 'note' => '', 'active' => true]];
        if ($patron) $patrons[self::EMAIL] = $patron + ['note' => ''];
        \cache\set(Type::Patronite, '', $patrons, 0, 60);
    }

    private function photo(): string
    {
        return random_bytes(32); // never in the ALPR cache
    }

    private function fill(int $n, ?int $createdAt = null): void
    {
        $stmt = \store\prepare('INSERT INTO photo_usage (user_email, sha1, created_at) VALUES (:e, :h, :t)');
        for ($i = 0; $i < $n; $i++)
            $stmt->execute([':e' => self::EMAIL, ':h' => sha1("fill$i"), ':t' => $createdAt ?? time()]);
    }

    public function testTiers(): void
    {
        $this->assertSame(50, \quota\tierFor($this->user())['limit']);

        foreach ([[9, 50], [10, 100], [24, 100], [25, 300], [49, 300], [50, null], [100, null]] as [$amount, $limit]) {
            $this->setPatron(['amount' => $amount, 'active' => true]);
            $this->assertSame($limit, \quota\tierFor($this->user())['limit'], "amount=$amount");
        }
    }

    public function testFormerPatronGetsFreeLimit(): void
    {
        $this->setPatron(['amount' => 50, 'active' => false]);
        $this->assertSame(50, \quota\tierFor($this->user())['limit']);
    }

    public function testSamePhotoIsChargedOnce(): void
    {
        $photo = $this->photo();
        $this->assertTrue(\quota\reserve($this->user(), $photo));
        $this->assertFalse(\quota\reserve($this->user(), $photo));
        $this->assertFalse(\quota\reserve($this->user(), $photo));
        $this->assertSame(1, \quota\status($this->user())['used']);
    }

    public function testExceedingLimitThrowsAndLeavesNoRow(): void
    {
        $this->fill(50);
        try {
            \quota\reserve($this->user(), $this->photo());
            $this->fail('QuotaExceededException expected');
        } catch (QuotaExceededException $e) {
            $this->assertSame(402, $e->getCode());
            $this->assertSame(50, $e->status['limit']);
            $this->assertSame(50, $e->status['used']);
        }
        $this->assertSame(50, \quota\status($this->user())['used']);
    }

    public function testAlreadyChargedPhotoIsFreeEvenOverLimit(): void
    {
        $photo = $this->photo();
        \quota\reserve($this->user(), $photo);
        $this->fill(49); // 50/50
        $this->assertFalse(\quota\reserve($this->user(), $photo));
    }

    public function testUnlimitedPatron(): void
    {
        $this->setPatron(['amount' => 50, 'active' => true]);
        $this->fill(500);
        $this->assertTrue(\quota\reserve($this->user(), $this->photo()));
        $this->assertNull(\quota\status($this->user())['limit']);
    }

    public function testReleaseGivesTheSlotBack(): void
    {
        $photo = $this->photo();
        \quota\reserve($this->user(), $photo);
        \quota\release($this->user(), $photo);
        $this->assertSame(0, \quota\status($this->user())['used']);
        $this->assertTrue(\quota\reserve($this->user(), $photo));
    }

    public function testRollingWindow(): void
    {
        $old = time() - (PHOTO_QUOTA_DAYS * 86400 + 60);
        $this->fill(50, $old);
        $this->assertSame(0, \quota\status($this->user())['used']);
        $this->assertTrue(\quota\reserve($this->user(), $this->photo())); // expired entries don't count

        $status = \quota\status($this->user());
        $this->assertSame(1, $status['used']);
        $this->assertEqualsWithDelta(time() + PHOTO_QUOTA_DAYS * 86400, $status['resetsAt'], 5);
    }

    public function testExpiredPhotoIsChargedAgain(): void
    {
        $photo = $this->photo();
        \store\prepare('INSERT INTO photo_usage (user_email, sha1, created_at) VALUES (:e, :h, :t)')
            ->execute([':e' => self::EMAIL, ':h' => sha1($photo), ':t' => time() - (PHOTO_QUOTA_DAYS * 86400 + 60)]);
        $this->assertTrue(\quota\reserve($this->user(), $photo));
        $this->assertSame(1, \quota\status($this->user())['used']);
    }

    public function testPurgeRemovesOnlyExpired(): void
    {
        $this->fill(3, time() - (PHOTO_QUOTA_DAYS * 86400 + 60));
        \quota\reserve($this->user(), $this->photo());
        $this->assertSame(3, \quota\purge());
        $this->assertSame(1, \quota\status($this->user())['used']);
    }
}
