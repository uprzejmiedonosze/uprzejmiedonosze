<?PHP

require_once(__DIR__ . '/SM.php');
require_once(__DIR__ . '/Status.php');

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
                $sub = new $class;
                $sub->set($value);
                $value = $sub;
            }
            $this->{$key} = $value;
        }
    }
}

