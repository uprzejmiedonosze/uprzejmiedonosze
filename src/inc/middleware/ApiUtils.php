<?PHP

function getParam($params, $name, $default=null) {
    $param = $params[$name] ?? $default;
    if(is_null($param)) {
        throw new Exception("Missing required parameter '$name'", 400);
    }
    return $param;
}
