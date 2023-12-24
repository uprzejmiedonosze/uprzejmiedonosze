<?PHP

/** 
 * Super class JSONObject able to recursively create new objects from JSON.
 */
class JSONObject extends stdClass {

    /**
     * Create empty object, or initiate it from JSON. 
     */
    public function __construct($json = null) {
        if($json){
            $this->__fromJson($json);
        }
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseMethodName)
     */
    public function __fromJson($json) {
        $this->set(is_string($json)? json_decode($json, true): $json);
    }

    /**
     * Initiate the object based on provided data.
     * @SuppressWarnings(PHPMD.MissingImport)
     */
    public function set($data) {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sub = new JSONObject;
                $sub->set($value);
                $value = $sub;
            }
            $this->{$key} = $value;
        }
    }

    public function __toString(){
        return serialize($this);
    }
}
?>