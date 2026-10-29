<?php

namespace UprzejmieDonosze\Tests;

/**
 * DatabaseTestCase plus the same per-test transaction on the separate wiki database (\wiki\db()).
 */
abstract class WikiTestCase extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \wiki\db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $db = \wiki\db();
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        parent::tearDown();
    }
}
