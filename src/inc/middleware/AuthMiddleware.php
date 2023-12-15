<?PHP

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;

use Kreait\Firebase\Factory;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpBadRequestException;

class AuthMiddleware implements MiddlewareInterface {
    public function process(Request $request, RequestHandler $handler): Response {
        global $_SERVER;
        $algorithm = 'RS256';
    
        if (!isset($_SERVER['HTTP_AUTHORIZATION']) || !preg_match('/Bearer\s(\S+)/', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            throw new HttpBadRequestException($request, 'Token not found in request');
        }
        $jwt = $matches[1];
        if (!$jwt)
            throw new HttpBadRequestException($request, 'Token not found in request');
    
        $keys  = getPublicKeys();
        
        @list($headersB64, $_payloadB64, $_sig) = explode('.', $jwt);
        $decoded = json_decode(base64_decode($headersB64), true);
        if (!isset($decoded['kid']))
            throw new HttpBadRequestException($request, 'Wrong `kid` in JWT header');

        $key = $keys[$decoded['kid']];
        
        try {
            $_token = JWT::decode($jwt, new Key($key, $algorithm));
        } catch (InvalidArgumentException $e) {
            throw new HttpBadRequestException($request, null, $e);
        } catch (DomainException // provided algorithm is unsupported OR provided key is invalid
            | SignatureInvalidException // provided JWT signature verification failed.
            | BeforeValidException // provided JWT is trying to be used before "nbf" claim OR before "iat" claim
            | ExpiredException // provided JWT is trying to be used after "exp" claim.
            | UnexpectedValueException // provided JWT is malformed OR is missing an algorithm / using an unsupported algorithm OR algorithm does not match provided key OR key ID in key/key-array is empty or invalid.
            $e) {
            
            throw new HttpForbiddenException($request, null, $e);
        }    
        $user = $this->verifyToken($jwt, $request);
        $request = $request->withAttribute('firebaseUser', $user);
        return $handler->handle($request);
    }

    private function verifyToken($token, $request){
        $factory = (new Factory)->withServiceAccount(__DIR__ . '/../../uprzejmiedonosze.localhost-firebase-adminsdk.json');
        $auth = $factory->createAuth();

        try {
            $verifiedIdToken = $auth->verifyIdToken($token);
            $claims = $verifiedIdToken->claims();
            return Array(
                'user_email' => ('%HOST%' === 'uprzejmiedonosze.localhost') ? 'e@nieradka.net' : $claims->get('email'),
                'user_name' => $claims->get('name'),
                'user_picture' => $claims->get('picture'),
                'user_id' => $claims->get('user_id')
            );
        } catch (Exception $e) {
            if (isProd()) \Sentry\captureException($e);
            throw new HttpForbiddenException($request, null, $e);
        } catch (Throwable $e) {
            if (isProd()) \Sentry\captureException($e);
            throw new HttpForbiddenException($request, null, $e);
        }
    }
}


