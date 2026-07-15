<?PHP

namespace mcp;

use user\User;

/**
 * Request-scoped holder for the authenticated user inside the MCP request.
 *
 * PHP-FPM handles one request per process, so a static holder set by the
 * transport middleware and read by the tool handlers is safe: there is no
 * cross-request bleed. The MCP SDK invokes tool handlers as plain callables
 * (not Slim route closures), so they cannot read PSR-7 request attributes
 * directly — this bridges that gap.
 */
final class McpIdentity {
    private static ?User $user = null;

    public static function set(User $user): void {
        self::$user = $user;
    }

    public static function currentUser(): User {
        if (self::$user === null) {
            throw new \RuntimeException('MCP tool invoked without an authenticated user');
        }
        return self::$user;
    }
}
