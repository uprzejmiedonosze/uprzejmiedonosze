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

    public function testSearchIgnoresCaseAndPolishDiacritics(): void
    {
        $_SESSION['user_email'] = 'apps-search@example.com';
        $_SESSION['user_id'] = 'phpunit-user-id';
        $user = new User();
        $user->email = 'apps-search@example.com';
        \user\save($user);
        foreach (['Wrocław', 'Łódź', 'Gdańsk'] as $city) {
            $app = Application::withUser($user);
            $app->statusHistory = [];
            $app->comments = [];
            $app->extensions = [];
            $app->status = 'confirmed';
            $app->address = new \JSONObject();
            $app->address->address = "Rynek 1, $city";
            \app\save($app);
        }

        $found = fn(string $q) => array_map(fn($a) => $a->address->address, \user\apps($user, 'all', $q));
        $this->assertSame(['Rynek 1, Wrocław'], $found('Wrocław'), 'Wrocław');
        $this->assertSame(['Rynek 1, Wrocław'], $found('wrocław'), 'wrocław');
        $this->assertSame(['Rynek 1, Wrocław'], $found('WROCŁAW'), 'WROCŁAW');
        $this->assertSame(['Rynek 1, Łódź'], $found('łódź'), 'łódź');
        $this->assertSame(['Rynek 1, Gdańsk'], $found('gdań'), 'gdań');
        $this->assertSame(['Rynek 1, Wrocław'], $found('wroc'), 'wroc');
        $this->assertSame(['Rynek 1, Wrocław'], $found('wroclaw'), 'wroclaw');
        $this->assertSame(['Rynek 1, Łódź'], $found('LODZ'), 'LODZ');
        $this->assertCount(1, \user\apps($user, 'all', 'rynek', 1, 2), 'paginacja po filtrowaniu');
        $this->assertCount(0, \user\apps($user, 'all', 'rynek', 1, 3));
        $this->assertCount(3, $found('%'), 'domyślna wartość REST nie filtruje');
        $this->assertCount(3, $found('all'));
    }
}
