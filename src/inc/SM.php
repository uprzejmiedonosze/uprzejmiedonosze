<?PHP

require_once(__DIR__ . '/JSONObject.php');

/**
 * Straz Miejska class LOL.
 */
class SM extends JSONObject{
    /**
     * Initites new SM from JSON.
     */
    public function __construct($json = null) {
        parent::__construct($json);
        @$this->address = get_object_vars($this->address);
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

    public function hasAPI(){
        // @TODO usunąć odwołania do BETY
        global $storage;
        return $this->api && $this->api !== 'email' && isLoggedIn() && $storage->getCurrentUser()->isBeta();
    }  

}

