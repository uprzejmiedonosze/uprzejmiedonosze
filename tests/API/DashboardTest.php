<?php

namespace UprzejmieDonosze\Tests\API;

require_once __DIR__ . '/../../export/inc/handlers/SessionApiHandler.php'; // loads API.php (dashboardData)

require_once __DIR__ . '/../../export/inc/UserRemoval.php';

use UprzejmieDonosze\Tests\DatabaseTestCase;
use user\User;

/** Gender-aware dashboard texts shared by web (`sexify` Twig filter) and GET /api/rest/user/dashboard. */
class DashboardTest extends DatabaseTestCase
{
    private function userNamed(string $name, string $email): User
    {
        $_SESSION['user_email'] = $email;
        $_SESSION['user_id'] = 'phpunit-user-id';
        $user = new User();
        $user->email = $email;
        $user->data->name = $name;
        $user->data->address = 'Testowa 1, Szczecin';
        \user\save($user);
        return $user;
    }

    public function testSexifyReplacesKnownTokensOnly(): void
    {
        $this->assertSame('że trafiłaś tutaj {nieznany}', sexify('że {trafilas} tutaj {nieznany}', SEXSTRINGS['f']));
        $this->assertSame('trafiłeś', sexify('{trafilas}', SEXSTRINGS['m']));
        $this->assertSame('trafiłaś/eś', sexify('{trafilas}', SEXSTRINGS['?']));
    }

    public function testEveryLevelTokenExistsForEverySex(): void
    {
        global $LEVELS;
        foreach ($LEVELS as $level) {
            preg_match_all('/\{(\w+)\}/', $level->introMsg, $m);
            foreach (SEXSTRINGS as $sex => $strings)
                foreach ($m[1] as $token)
                    $this->assertArrayHasKey($token, $strings, "levels.json token {{$token}} missing in SEXSTRINGS['$sex']");
        }
    }

    public function testDashboardIsLocalizedForFemaleUser(): void
    {
        $d = dashboardData($this->userNamed('Anna Kowalska', 'dash-f@example.com'));
        $this->assertSame('Anna', $d['name']);
        $this->assertStringContainsString('trafiłaś', $d['introMsg']);
        $this->assertStringNotContainsString('{', $d['introMsg']);
        $this->assertSame('Wkurzona', $d['levels'][0]['desc']);
        $this->assertSame('Obrończyni zieleni', $d['badges'][0]['name']);
    }

    public function testDashboardIsLocalizedForMaleUser(): void
    {
        $d = dashboardData($this->userNamed('Jan Kowalski', 'dash-m@example.com'));
        $this->assertStringContainsString('trafiłeś', $d['introMsg']);
        $this->assertSame('Wkurzony', $d['levels'][0]['desc']);
        $this->assertSame('Obrońca zieleni', $d['badges'][0]['name']);
        $this->assertCount(5, $d['levels']);
        $this->assertTrue($d['levels'][0]['active']); // a fresh user is at level 0
        $this->assertSame(1, $d['rank']['index']);
        $this->assertSame(5, $d['rank']['total']);
        $this->assertSame('Wkurzony', $d['rank']['desc']);
        $this->assertSame('Początkujący', $d['rank']['next']['desc']);
        $this->assertSame(1, $d['rank']['next']['missing']); // level 1 starts at 1 penalty point
    }

    public function testSelfDeleteRequiresMatchingEmail(): void
    {
        $user = $this->userNamed('Jan Kowalski', 'dash-del@example.com');
        $this->assertFalse(\admin\selfDelete($user, 'ktos-inny@example.com'));
        $this->assertSame('dash-del@example.com', \user\get('dash-del@example.com')->getEmail()); // still there
    }

    public function testFreshBypassesTheStatsCache(): void
    {
        $user = $this->userNamed('Jan Kowalski', 'dash-fresh@example.com');
        $before = dashboardData($user)['stats']['active'] ?? 0; // fills the 24 h cache

        $app = \app\Application::withUser($user);
        $app->statusHistory = [];
        $app->comments = [];
        $app->extensions = [];
        $app->status = 'confirmed';
        \app\save($app);

        $this->assertSame($before, dashboardData($user)['stats']['active'] ?? 0, 'cached stats are stale by design');
        $this->assertSame($before + 1, dashboardData($user, fresh: true)['stats']['active'] ?? 0);
        $this->assertSame($before + 1, dashboardData($user)['stats']['active'] ?? 0, 'fresh also refreshes the cache');
    }
}
