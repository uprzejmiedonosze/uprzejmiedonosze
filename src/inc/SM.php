<?PHP

require_once(__DIR__ . '/JSONObject.php');

/**
 * Application class.
 */
class SM extends JSONObject{
    /**
     * Initites new SM from JSON.
     */
    public function __construct($json = null) {
        parent::__construct($json);
    }

    public function getAddress(){
        return $this->address;
    }

    public function getLatexAddress(){
        return join(' \\\\ ', (array) $this->address);
    }

    public function getEmail(){
        return $this->email;
    }

    public function getCity(){
        return $this->city;
    }

    public function getHint(){
        return $this->hint;
    }

    public function isAPI(){
        return !!$this->api;
    }  

}

