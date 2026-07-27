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
}
