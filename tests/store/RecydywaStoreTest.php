<?php

namespace UprzejmieDonosze\Tests\Store;

define('DB_FILENAME', __DIR__ . '/../../docker/db/store.sqlite');
//require_once __DIR__ . '/../../export/inc/store/index.php';

use PHPUnit\Framework\TestCase;

class RecydywaStoreTest extends TestCase
{
    public function testCache(): void
    {
        self::assertTrue(true);
        //\recydywa\get('plate', false);
    }
}
