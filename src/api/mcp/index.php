<?PHP

date_default_timezone_set('Europe/Warsaw');

// MCP speaks its own JSON-RPC protocol over a raw request body — run sessionless,
// like the REST API, so PHP sessions never touch the request.
$DISABLE_SESSION = true;

const INC_DIR = __DIR__ . '/../../../inc';

require_once(INC_DIR . '/Logger.php');
require(INC_DIR . '/middleware/ApiErrorHandler.php');
set_error_handler("ApiErrorHandler");

require(INC_DIR . '/include.php');
require(INC_DIR . '/API.php');
require(INC_DIR . '/middleware/JsonErrorRenderer.php');
require(INC_DIR . '/middleware/AuthMiddleware.php');
require(INC_DIR . '/middleware/UserMiddleware.php');

require(INC_DIR . '/oauth/Entities.php');
require(INC_DIR . '/oauth/Repositories.php');

require(INC_DIR . '/mcp/McpIdentity.php');
require(INC_DIR . '/mcp/McpMemcacheSessionStore.php');
require(INC_DIR . '/mcp/McpAuthMiddleware.php');
require(INC_DIR . '/mcp/ReportMcpTools.php');
require(INC_DIR . '/mcp/McpServerFactory.php');

use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;

$app = AppFactory::create();
$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(!isProd(), true, !isProd());
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('application/json');
$errorHandler->registerErrorRenderer('application/json', JsonErrorRenderer::class);

/**
 * Bridges the Firebase UID from the verified token into $_SESSION so the
 * crypto layer can decrypt per-user data. User and Application records are
 * encrypted with the owner's Firebase user_id (see User::decode /
 * Application::decode), which the session login flow puts in
 * $_SESSION['user_id'] (SessionApiHandler). This token-authenticated,
 * sessionless path must do the same before UserMiddleware reads the user.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
class McpSessionMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        $firebaseUser = $request->getAttribute('firebaseUser');
        if ($firebaseUser) {
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            $_SESSION['user_id'] = $firebaseUser['user_id'] ?? null;
            $_SESSION['user_email'] = $firebaseUser['user_email'] ?? null;
        }
        return $handler->handle($request);
    }
}

/**
 * Bridges the authenticated user (resolved by UserMiddleware into the PSR-7
 * request) into the request-scoped McpIdentity, which the SDK tool handlers
 * read. The SDK invokes handlers as plain callables, so they cannot reach the
 * request attributes themselves.
 */
class McpIdentityMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        \mcp\McpIdentity::set($request->getAttribute('user'));
        \mcp\McpIdentity::setScopes($request->getAttribute('mcpScopes', []));
        return $handler->handle($request);
    }
}

$mcpHandler = function (Request $request, Response $response) {
    $server = \mcp\buildServer();
    // The SDK's DNS-rebinding allowlist defaults to localhost only, which would
    // reject real remote clients. Widen it to the deployment host; keep the
    // other default transport middleware (CORS, protocol-version).
    $middleware = array_map(
        fn ($m) => $m instanceof DnsRebindingProtectionMiddleware
            ? new DnsRebindingProtectionMiddleware(['localhost', '127.0.0.1', '[::1]', HOST])
            : $m,
        StreamableHttpTransport::defaultMiddleware()
    );
    return $server->run(new StreamableHttpTransport($request, middleware: $middleware));
};

// CORS preflight must not require authorization.
$app->options('/mcp', function (Request $request, Response $response) {
    return $response;
});

$app->map(['POST', 'GET', 'DELETE'], '/mcp', $mcpHandler)
    ->add(new McpIdentityMiddleware())
    ->add(new UserMiddleware(createIfNonExists: false))
    ->add(new McpSessionMiddleware())
    ->add(new McpAuthMiddleware());

$app->run();
