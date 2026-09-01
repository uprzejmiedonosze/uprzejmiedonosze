<?php

namespace UprzejmieDonosze\Tests;

use PHPUnit\Framework\TestCase;
use Aws\MockHandler;
use Aws\Result;
use store\S3;

/**
 * Tests for \storage\* pure / no-network functions.
 *
 * The test bootstrap uses HOST=staging, so:
 *   isEnabled()  → true
 *   cdnPrefix()  → 'cdn2stg'
 *
 * Tests that would require real S3 connectivity are skipped.
 * Tests that exercise code paths BEFORE any network call are safe.
 */
class StorageTest extends TestCase
{
    protected function tearDown(): void
    {
        // Undo any MockHandler override from this test. Uses harmless
        // placeholder config (not \B2_REGION etc., which may be empty in
        // this test environment and would fail S3Client construction) —
        // nothing else in this test file ever exercises this instance
        // for real, so the values themselves don't matter.
        \storage\b2(new S3('reset', 'k', 's', 'https://example.test', 'us-east-1'));
    }

    public function testCdnPrefix(): void
    {
        $this->assertTrue(str_contains(\storage\cdnPrefix(), 'cdn2'));
    }

    public function testIsEnabled(): void
    {
        $this->assertTrue(\storage\isEnabled());
    }

    /**
     * upload() guards with file_exists() before contacting S3.
     * Calling it with a non-existent local path must be a no-op (no exception).
     */
    public function testUploadIsNoOpWhenLocalFileDoesNotExist(): void
    {
        \storage\upload('/nonexistent/totally-missing.jpg', 'cdn2stg/test/x.jpg');
        $this->assertTrue(true);
    }

    /**
     * exists() must resolve from B2.
     */
    public function testExistsResolvesFromB2(): void
    {
        $b2Handler = new MockHandler([new Result(['ContentLength' => 42])]);

        \storage\b2(new S3('b2-bucket', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $b2Handler]));

        $this->assertTrue(\storage\exists('cdn2stg/test/x.jpg'));
        $this->assertCount(0, $b2Handler);
    }

    /**
     * delete() must only touch B2.
     */
    public function testDeleteOnlyTouchesB2(): void
    {
        $b2Handler = new MockHandler([new Result([])]);

        \storage\b2(new S3('b2-bucket', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $b2Handler]));

        \storage\delete('cdn2stg/test/x.jpg');
        $this->assertCount(0, $b2Handler);
    }

    /**
     * S3::uploadPrivate() must not require an ACL (backups are private).
     * Assert it resolves against the mock handler with a known key.
     */
    public function testUploadPrivateWithoutAcl(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ud-test');
        file_put_contents($tmp, 'backup-content');
        $b2Handler = new MockHandler([new Result([])]);

        $s3 = new S3('backup-bucket', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $b2Handler]);
        $ok = $s3->uploadPrivate($tmp, 'db/store-2026-01-01-daily.sql.gz');
        unlink($tmp);

        $this->assertTrue($ok);
        $this->assertCount(0, $b2Handler);
    }

    /**
     * S3::listObjects() must return keys for a prefix.
     */
    public function testListObjectsReturnsKeys(): void
    {
        $b2Handler = new MockHandler([
            new Result(['Contents' => [
                ['Key' => 'db/store-2026-01-01-daily.sql.gz'],
                ['Key' => 'db/store-2026-01-08-daily.sql.gz'],
            ]]),
        ]);

        $s3 = new S3('backup-bucket', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $b2Handler]);
        $keys = $s3->listObjects('db/store-');

        $this->assertCount(2, $keys);
        $this->assertContains('db/store-2026-01-01-daily.sql.gz', $keys);
    }

    /**
     * S3::listObjects() must return an empty array on error (not throw).
     */
    public function testListObjectsReturnsEmptyOnError(): void
    {
        $b2Handler = new MockHandler([new \Aws\Exception\AwsException('boom', new \Aws\Command('ListObjects'))]);

        $s3 = new S3('backup-bucket', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $b2Handler]);
        $this->assertSame([], $s3->listObjects('db/store-'));
    }
}
