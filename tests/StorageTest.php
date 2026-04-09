<?php

namespace UprzejmieDonosze\Tests;

use PHPUnit\Framework\TestCase;

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
}
