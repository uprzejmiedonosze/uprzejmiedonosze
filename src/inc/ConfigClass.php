<?PHP

require_once(__DIR__ . '/SM.php');
require_once(__DIR__ . '/Status.php');
require_once(__DIR__ . '/Category.php');
require_once(__DIR__ . '/StopAgresji.php');

/**
 * SM Config class.
 */
class ConfigClass {
    /**
     * Initites new SM from JSON.
     */
    public function __construct($json, $class) {
        $data = json_decode($json, true);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sub = new $class($value);
                $value = $sub;
            }
            $value->key = $key;
            $this->{$key} = $value;
        }
    }
}
