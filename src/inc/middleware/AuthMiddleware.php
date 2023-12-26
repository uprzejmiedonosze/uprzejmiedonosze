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

class AuthMiddleware implements MiddlewareInterface {
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
    
        $keys  = $this->getPublicKeys($request);
        
        @list($headersB64, $_payloadB64, $_sig) = explode('.', $jwt);
        $decoded = json_decode(base64_decode($headersB64), true);
        if (!isset($decoded['kid']))
            throw new HttpBadRequestException($request, 'Wrong `kid` in JWT header');

        $key = $keys[$decoded['kid']];
        
        try {
            $_token = JWT::decode($jwt, new Key($key, $algorithm));
        } catch (InvalidArgumentException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        } catch (DomainException // provided algorithm is unsupported OR provided key is invalid
            | SignatureInvalidException // provided JWT signature verification failed.
            | BeforeValidException // provided JWT is trying to be used before "nbf" claim OR before "iat" claim
            | ExpiredException // provided JWT is trying to be used after "exp" claim.
            | UnexpectedValueException // provided JWT is malformed OR is missing an algorithm / using an unsupported algorithm OR algorithm does not match provided key OR key ID in key/key-array is empty or invalid.
            $e) {
            
            throw new HttpForbiddenException($request, $e->getMessage(), $e);
        }    
        $user = $this->verifyToken($jwt, $request);
        $request = $request->withAttribute('firebaseUser', $user);
        return $handler->handle($request);
    }

    private function verifyToken($token, $request){
        $factory = (new Factory)->withServiceAccount(__DIR__ . '/../../%HOST%-firebase-adminsdk.json');
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

        $cache = new Memcache;
        $cache->connect('localhost', 11211);
        $cacheKey = '%HOST%-firebase-keys';

        $keys = $cache->get($cacheKey);
        if ($keys) return json_decode($keys, true);

        $publicKeys = file_get_contents($publicKeyUrl);
        if (!$publicKeys)
            throw new HttpInternalServerErrorException($request, 'Failed to fetch JWT public keys.');

        $cacheControl = $this->parseHeaders($http_response_header)['Cache-Control'];
        preg_match('/max-age=(\d+)/', $cacheControl, $out);
        $timeout = $out[1];
        $cache->set($cacheKey, $publicKeys, 0, (int)$timeout);

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
               $head[trim($header[0])] = trim($header[1]);
               continue;
           }
           $head[] = $v;
           if (preg_match("#HTTP/[0-9\.]+\s+([0-9]+)#", $v, $out))
               $head['reponse_code'] = intval($out[1]);
       }
       return $head;
   }

}
