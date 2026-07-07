<?php

namespace UprzejmieDonosze\Tests;

use PHPUnit\Framework\TestCase;
use Aws\Command;
use Aws\Exception\AwsException;
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
        // nothing else in this test file ever exercises these instances
        // for real, so the values themselves don't matter.
        \storage\b2(new S3('reset', 'k', 's', 'https://example.test', 'us-east-1'));
        \storage\s3(new S3('reset', 'k', 's', 'https://example.test', 'us-east-1'));
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
     * exists() must resolve from B2 without ever touching the Hetzner fallback.
     */
    public function testExistsResolvesFromB2WithoutTouchingFallback(): void
    {
        $b2Handler = new MockHandler([new Result(['ContentLength' => 42])]);
        $s3Handler = new MockHandler(); // left empty — must never be called

        \storage\b2(new S3('b2-bucket', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $b2Handler]));
        \storage\s3(new S3('s3-bucket', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $s3Handler]));

        $this->assertTrue(\storage\exists('cdn2stg/test/x.jpg'));
        $this->assertCount(0, $b2Handler);
        $this->assertCount(0, $s3Handler); // started empty and stayed empty — never invoked
    }

    /**
     * exists() must fall back to Hetzner S3 when B2 doesn't have the key yet
     * (content not migrated over from Hetzner).
     */
    public function testExistsFallsBackToS3WhenMissingFromB2(): void
    {
        $notFound = new AwsException('Not Found', new Command('HeadObject'), ['code' => 'NoSuchKey']);

        $b2Handler = new MockHandler([$notFound]);
        $s3Handler = new MockHandler([new Result(['ContentLength' => 7])]);

        \storage\b2(new S3('b2-bucket', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $b2Handler]));
        \storage\s3(new S3('s3-bucket', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $s3Handler]));

        $this->assertTrue(\storage\exists('cdn2stg/test/x.jpg'));
        $this->assertCount(0, $b2Handler);
        $this->assertCount(0, $s3Handler);
    }

    /**
     * delete() must only touch B2 — Hetzner S3 is a read-only fallback and
     * must never be written to or deleted from.
     */
    public function testDeleteOnlyTouchesB2NeverS3(): void
    {
        $b2Handler = new MockHandler([new Result([])]);
        $s3Handler = new MockHandler(); // left empty — must never be called

        \storage\b2(new S3('b2-bucket', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $b2Handler]));
        \storage\s3(new S3('s3-bucket', 'k', 's', 'https://example.test', 'us-east-1', ['handler' => $s3Handler]));

        \storage\delete('cdn2stg/test/x.jpg');
        $this->assertCount(0, $b2Handler);
        $this->assertCount(0, $s3Handler); // started empty and stayed empty — never invoked
    }
}
