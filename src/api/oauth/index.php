<?PHP

date_default_timezone_set('Europe/Warsaw');

const INC_DIR = __DIR__ . '/../../../inc';

require_once(INC_DIR . '/Logger.php');
require(INC_DIR . '/middleware/ApiErrorHandler.php');
set_error_handler("ApiErrorHandler");

require(INC_DIR . '/include.php');
require(INC_DIR . '/middleware/JsonErrorRenderer.php');
require(INC_DIR . '/oauth/Entities.php');
require(INC_DIR . '/oauth/Repositories.php');
require(INC_DIR . '/oauth/OAuthServerFactory.php');
require(INC_DIR . '/oauth/Endpoints.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$app = AppFactory::create();
$app->addRoutingMiddleware();
// Parses application/json (register) and application/x-www-form-urlencoded (token).
$app->addBodyParsingMiddleware();

// CORS: browser-based MCP clients (e.g. MCP Inspector) call these endpoints
// cross-origin, so the discovery/register/token fetches need CORS headers.
// These are public OAuth endpoints (no cookies; PKCE protects the exchange),
// so reflecting the Origin is safe.
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    $origin = $request->getHeaderLine('Origin');
    if ($origin !== '') {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Vary', 'Origin')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, MCP-Protocol-Version');
    }
    return $response;
});

$errorMiddleware = $app->addErrorMiddleware(!isProd(), true, !isProd());
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('application/json');
$errorHandler->registerErrorRenderer('application/json', JsonErrorRenderer::class);

$app->get('/.well-known/oauth-authorization-server', \oauth\authorizationServerMetadata(...));
// Both the RFC 9728 resource-path form and the bare form, since MCP clients
// probe either.
$app->get('/.well-known/oauth-protected-resource/mcp', \oauth\protectedResourceMetadata(...));
$app->get('/.well-known/oauth-protected-resource', \oauth\protectedResourceMetadata(...));
$app->post('/oauth/register', \oauth\register(...));
$app->post('/oauth/token', \oauth\token(...));
$app->post('/oauth/revoke', \oauth\revoke(...));

// CORS preflight for any of the above (the middleware adds the headers).
$app->options('/{routes:.+}', fn (Request $request, Response $response) => $response);

$app->run();
