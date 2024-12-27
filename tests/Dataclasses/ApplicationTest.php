<?php

namespace UprzejmieDonosze\Tests\Dataclasses;

use PHPUnit\Framework\TestCase;
use app\Application;
use user\User;


class ApplicationTest extends TestCase
{
    public function testIsEditable()
    {
        global $STATUSES;
        $STATUSES = [
            'draft' => (object)['editable' => true],
            'submitted' => (object)['editable' => false]
        ];

        $app = Application::withUser(new User());
        $app->status = 'draft';
        $this->assertTrue($app->isEditable());

        $app->status = 'submitted';
        $this->assertFalse($app->isEditable());
    }

    public function testGetRevision()
    {
        $app = Application::withUser(new User());
        $this->assertEquals(0, $app->getRevision());

        $app->statusHistory = ['status1', 'status2'];
        $this->assertEquals(2, $app->getRevision());
    }

    public function testIsAppOwner()
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('test@example.com');

        $app = Application::withUser(new User());
        $app->user = (object)['email' => 'test@example.com'];

        $this->assertTrue($app->isAppOwner($user));

        $user->method('getEmail')->willReturn('other@example.com');

        $this->assertFalse($app->isAppOwner(null));
    }
}