<?PHP

use \Memcache as Memcache;
use Slim\Exception\HttpBadRequestException;

/**
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function isAjax() {
    global $_SERVER;
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
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


/**
 * Retrieves (cached) public keys from Firebase's server
 */
function getPublicKeys() {
    $publicKeyUrl = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    $cache = new Memcache;
    $cache->connect('localhost', 11211);
    $cacheKey = '%HOST%-firebase-keys';

    $keys = $cache->get($cacheKey);
    if ($keys) return json_decode($keys, true);

    $publicKeys = file_get_contents($publicKeyUrl);
    if (!$publicKeys)
        raiseError('Failed to fetch JWT public keys.', 501);

    $cacheControl = parseHeaders($http_response_header)['Cache-Control'];
    preg_match('/max-age=(\d+)/', $cacheControl, $out);
    $timeout = $out[1];
    $cache->set($cacheKey, $publicKeys, 0, (int)$timeout);

    return json_decode($publicKeys, true);
}

/**
 * Retrieves key_id from JWT.
 * 
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
function decodeKid($jwt) {

}

