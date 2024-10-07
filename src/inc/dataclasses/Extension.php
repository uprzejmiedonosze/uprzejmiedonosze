<?PHP

require_once(__DIR__ . '/JSONObject.php');

/**
 * Extension class.
 */
class Extension extends JSONObject{
    /**
     * Initiates new Extension from JSON.
     */
    public function __construct($json = null) {
        parent::__construct($json);
    }

}
