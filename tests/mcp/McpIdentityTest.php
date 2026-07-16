<?php

namespace UprzejmieDonosze\Tests\Mcp;

require_once __DIR__ . '/../../export/inc/mcp/McpIdentity.php';

use mcp\McpIdentity;
use ReflectionClass;
use RuntimeException;
use user\User;
use UprzejmieDonosze\Tests\DatabaseTestCase;

/**
 * McpIdentity holds its state in static properties (see the class docblock —
 * safe per-PHP-FPM-request, but that means each test here must reset it
 * itself; PHPUnit gives no such isolation for statics between test methods.
 */
class McpIdentityTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetIdentity();
    }

    protected function tearDown(): void
    {
        $this->resetIdentity();
        parent::tearDown();
    }

    private function resetIdentity(): void
    {
        $ref = new ReflectionClass(McpIdentity::class);

        $user = $ref->getProperty('user');
        $user->setAccessible(true);
        $user->setValue(null, null);

        $scopes = $ref->getProperty('scopes');
        $scopes->setAccessible(true);
        $scopes->setValue(null, []);
    }

    public function testCurrentUserThrowsWhenNeverSet(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MCP tool invoked without an authenticated user');
        McpIdentity::currentUser();
    }

    public function testSetAndCurrentUserRoundTrip(): void
    {
        $user = new User();
        $user->data->email = 'a@b.com';

        McpIdentity::set($user);

        self::assertSame($user, McpIdentity::currentUser());
    }

    public function testRequireScopePassesWhenGranted(): void
    {
        McpIdentity::setScopes(['reports:read', 'reports:status:write']);
        McpIdentity::requireScope('reports:read');
        McpIdentity::requireScope('reports:status:write');
        self::assertTrue(true); // reaching here means neither call threw
    }

    public function testRequireScopeThrowsWhenNotGranted(): void
    {
        McpIdentity::setScopes(['reports:read']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("requires the 'reports:status:write' OAuth scope");
        McpIdentity::requireScope('reports:status:write');
    }

    public function testRequireScopeThrowsWhenNoScopesSetAtAll(): void
    {
        $this->expectException(RuntimeException::class);
        McpIdentity::requireScope('reports:read');
    }
}
