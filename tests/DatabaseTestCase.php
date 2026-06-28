<?php

namespace UprzejmieDonosze\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base class that isolates every test from the others.
 *
 *  - $_SESSION is cleared before and after each test. The app reads the current
 *    user's identity straight from the session superglobal, so a leaked session
 *    would otherwise let one test impersonate another's user.
 *  - All DB work runs inside a transaction that is rolled back in tearDown, so
 *    rows never leak into the shared SQLite test database between tests.
 *
 * The store exposes a single shared PDO connection (\store\store()) and no
 * production code opens its own transaction (semaphore locks live in memcached,
 * not SQLite), so a per-test BEGIN/ROLLBACK fully contains every write.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        \store\store()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $db = \store\store();
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION = [];
        parent::tearDown();
    }
}
