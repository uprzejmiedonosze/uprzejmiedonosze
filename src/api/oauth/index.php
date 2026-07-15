<?PHP

date_default_timezone_set('Europe/Warsaw');

// Machine-to-machine OAuth endpoints: sessionless, JSON/form.
$DISABLE_SESSION = true;

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

use Slim\Factory\AppFactory;

$app = AppFactory::create();
$app->addRoutingMiddleware();
// Parses application/json (register) and application/x-www-form-urlencoded (token).
$app->addBodyParsingMiddleware();

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

$app->run();
