<?PHP

require_once(__DIR__ . '/JSONObject.php');

/**
 * Status class.
 */
class Status extends JSONObject{
    /**
     * Initites new Status from JSON.
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    public function __construct($json = null) {
        parent::__construct($json);
        @$this->allowed = (array)$this->allowed;
    }

    public function getDesc(){
        return $this->descs;
    }

    public function getAction(){
        return $this->action;
    }

    public function getIcon(){
        return $this->icon;
    }

    public function getClass(){
        return $this->class;
    }
}

