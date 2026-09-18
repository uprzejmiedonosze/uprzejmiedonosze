<?PHP

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Bridges the Firebase UID from the verified token into $_SESSION so the
 * crypto layer can decrypt per-user data. User and Application records are
 * encrypted with the owner's Firebase user_id (see User::decode /
 * Application::decode), which the session login flow puts in
 * $_SESSION['user_id'] (SessionApiHandler). Every token-authenticated,
 * sessionless path (REST, MCP) must do the same before UserMiddleware reads
 * the user, otherwise encode() falls into its "no user_id" plaintext branch
 * and decode() throws on any row that was encrypted via the web session.
 *
 * Add this BETWEEN AuthMiddleware and UserMiddleware, i.e. later in the
 * ->add() chain than UserMiddleware but earlier than AuthMiddleware, since
 * Slim runs the last-added middleware first:
 *   ->add(new UserMiddleware(...))
 *   ->add(new TokenSessionMiddleware())
 *   ->add(new AuthMiddleware());
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
class TokenSessionMiddleware implements MiddlewareInterface {
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
