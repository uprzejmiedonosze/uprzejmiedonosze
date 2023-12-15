<?PHP

use \Memcache as Memcache;

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


function getParam($params, $name, $default=null) {
    $param = $params[$name] ?? $default;
    if(is_null($param)) {
        throw new Exception("Missing required parameter '$name'");
    }
    return $param;
}
