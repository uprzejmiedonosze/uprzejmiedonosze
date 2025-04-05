<?php

namespace UprzejmieDonosze\Tests\Dataclasses;

use Exception;
use MissingParamException;
use PHPUnit\Framework\TestCase;
use user\User;

class SemaphoreTest extends TestCase
{
    public function testIsRegistered()
    {
        \semaphore\acquire("id1", 'id1');
        $this->assertTrue(true);
        \semaphore\release("id1", 'id1');
    }
}
