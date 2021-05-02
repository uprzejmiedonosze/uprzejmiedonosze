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
        return "SM " . $this->city;
    }

    public function getHint(){
        return $this->hint;
    }

    public function getName(){
        return $this->address[0];
    }

    public function hasAPI(){
        return $this->api && $this->api !== 'Mail';
    }

    public function automated(){
        return (boolean)$this->api;
    }

    public function unknown(){
        return $this->city == null;
    }

}

