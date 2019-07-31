<?PHP

require_once(__DIR__ . '/SM.php');

/**
 * SM Config class.
 */
class SMConfig {
    /**
     * Initites new SM from JSON.
     */
    public function __construct($json) {
        $data = json_decode($json, true);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sub = new SM;
                $sub->set($value);
                $value = $sub;
            }
            $this->{$key} = $value;
        }
    }
}

