<?php

namespace UprzejmieDonosze\Tests\Store;

use app\Application;
use UprzejmieDonosze\Tests\DatabaseTestCase;
use user\User;

/** \user\apps() status filters: 'all' keeps archived, 'active' (web list default) hides them. */
class UserAppsStatusTest extends DatabaseTestCase
{
    private function appWithStatus(User $user, string $status): void
    {
        $app = Application::withUser($user);
        $app->statusHistory = [];
        $app->comments = [];
        $app->extensions = [];
        $app->status = $status;
        \app\save($app);
    }

    public function testActiveHidesArchivedAndDrafts(): void
    {
        $_SESSION['user_email'] = 'apps-status@example.com';
        $_SESSION['user_id'] = 'phpunit-user-id';
        $user = new User();
        $user->email = 'apps-status@example.com';
        \user\save($user);
        foreach (['confirmed', 'confirmed-waiting', 'archived', 'draft'] as $status)
            $this->appWithStatus($user, $status);

        $statuses = fn(string $filter) => array_map(fn($a) => $a->status, \user\apps($user, $filter));
        $this->assertEqualsCanonicalizing(['confirmed', 'confirmed-waiting', 'archived'], $statuses('all'));
        $this->assertEqualsCanonicalizing(['confirmed', 'confirmed-waiting'], $statuses('active'));
        $this->assertSame(['archived'], $statuses('archived'));
    }
}
