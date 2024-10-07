<?PHP

require_once(__DIR__ . '/SM.php');
require_once(__DIR__ . '/Status.php');
require_once(__DIR__ . '/Category.php');
require_once(__DIR__ . '/Extension.php');
require_once(__DIR__ . '/StopAgresji.php');
require_once(__DIR__ . '/Level.php');

/**
 * SM Config class.
 */
class ConfigClass extends stdClass {
    /**
     * Initiates new SM from JSON.
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
