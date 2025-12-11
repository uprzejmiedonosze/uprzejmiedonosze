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
use Slim\Exception\HttpInternalServerErrorException;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AuthMiddleware implements MiddlewareInterface {
    /**
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function process(Request $request, RequestHandler $handler): Response {
        $algorithm = 'RS256';

        if (!$request->hasHeader("Authorization")) {
            throw new HttpBadRequestException($request, 'Token not found in request');
        }
        $header = $request->getHeader("Authorization");
        $bearer = trim($header[0]);
        if(!preg_match('/Bearer\s(\S+)/', $bearer, $matches)) {
            throw new HttpBadRequestException($request, 'Token not found in request');
        }
        $jwt = $matches[1];
        if (!$jwt) {
            throw new HttpBadRequestException($request, 'Token not found in request');
        }

        $usingEmulator = !empty(getenv('FIREBASE_AUTH_EMULATOR_HOST'));

        if (!$usingEmulator) {
            $keys  = $this->getPublicKeys($request);

            @list($headersB64, $payloadB64, $sig) = explode('.', $jwt);
            $decoded = json_decode(base64_decode($headersB64), true);
            if (!isset($decoded['kid']))
                throw new HttpBadRequestException($request, 'Wrong `kid` in JWT header');

            $key = $keys[$decoded['kid']];

            try {
                $decodedToken = JWT::decode($jwt, new Key($key, $algorithm));
            } catch (InvalidArgumentException $e) {
                throw new HttpBadRequestException($request, $e->getMessage(), $e);
            } catch (DomainException // provided algorithm is unsupported OR provided key is invalid
                | SignatureInvalidException // provided JWT signature verification failed.
                | BeforeValidException // provided JWT is trying to be used before "nbf" claim OR before "iat" claim
                | ExpiredException // provided JWT is trying to be used after "exp" claim.
                | UnexpectedValueException // provided JWT is malformed OR is missing an algorithm / using an unsupported algorithm OR algorithm does not match provided key OR key ID in key/key-array is empty or invalid.
                $e) {

                if ($e instanceof ExpiredException && isDev()) {
                    $user = Array(
                        'user_email' => $decodedToken->email,
                        'user_name' => $decodedToken->name
                    );
                    $request = $request->withAttribute('firebaseUser', $user);
                    return $handler->handle($request);
                }

                throw new HttpForbiddenException($request, $e->getMessage(), $e);
            }
        }

        $user = $this->verifyToken($jwt, $request);
        $request = $request->withAttribute('firebaseUser', $user);
        return $handler->handle($request);
    }

    private function verifyToken($token, $request){
        // $firebaseUser = \cache\get($token);
        //if ($firebaseUser) return json_decode($firebaseUser, true);

        $usingEmulator = !empty(getenv('FIREBASE_AUTH_EMULATOR_HOST'));

        if ($usingEmulator) {
            @list($headersB64, $payloadB64, $sig) = explode('.', $token);
            $payload = json_decode(base64_decode($payloadB64), true);

            if (!$payload || !isset($payload['email'])) {
                throw new HttpForbiddenException($request, 'Invalid emulator token');
            }

            return Array(
                'user_email' => (isDev()) ? 'e@nieradka.net' : $payload['email'],
                'user_name' => $payload['name'] ?? '',
                'user_picture' => $payload['picture'] ?? '',
                'user_id' => $payload['user_id'] ?? $payload['sub'] ?? '',
                'token' => $token
            );
        }

        $factory = (new Factory)->withServiceAccount(__DIR__ . '/../../' . HOST . '-firebase-adminsdk.json');
        $auth = $factory->createAuth();

        try {
            $verifiedIdToken = $auth->verifyIdToken($token);
            $claims = $verifiedIdToken->claims();
            $firebaseUser = Array(
                'user_email' => (isDev()) ? 'e@nieradka.net' : $claims->get('email'),
                'user_name' => $claims->get('name'),
                'user_picture' => $claims->get('picture'),
                'user_id' => $claims->get('user_id'),
                'token' => $token
            );
            // \cache\set($token, json_encode($firebaseUser), 0, $claims->get('exp')->getTimestamp() - 60);
            return $firebaseUser;
        } catch (Exception $e) {
            if (isProd()) \Sentry\captureException($e);
            throw new HttpForbiddenException($request, $e->getMessage(), $e);
        } catch (Throwable $e) {
            if (isProd()) \Sentry\captureException($e);
            throw new HttpForbiddenException($request, $e->getMessage(), $e);
        }
    }

    /**
     * Retrieves (cached) public keys from Firebase's server
     */
    private function getPublicKeys($request) {
        $publicKeyUrl = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
        $keys = \cache\get(\cache\Type::FirebaseKeys);
        if ($keys) return json_decode($keys, true);

        $publicKeys = file_get_contents($publicKeyUrl);
        if (!$publicKeys)
            throw new HttpInternalServerErrorException($request, 'Failed to fetch JWT public keys.');

        $cacheControl = $this->parseHeaders($http_response_header)['cache-control'] ?? 'cache-control: public, max-age=600, must-revalidate, no-transform';
        preg_match('/max-age=(\d+)/', $cacheControl, $out);
        $timeout = $out[1];
        \cache\set(\cache\Type::FirebaseKeys, "", $publicKeys, 0, (int)$timeout);

        return json_decode($publicKeys, true);
    }

    private /**
    * @SuppressWarnings(PHPMD.UnusedLocalVariable)
    */
   function parseHeaders($headers) {
       $head = array();
       foreach ($headers as $k => $v) {
           $header = explode(':', $v, 2);
           if (isset($header[1])) {
               $head[strtolower(trim($header[0]))] = trim($header[1]);
               continue;
           }
           $head[] = $v;
           if (preg_match("#HTTP/[0-9\.]+\s+([0-9]+)#", $v, $out))
               $head['reponse_code'] = intval($out[1]);
       }
       return $head;
   }

}
