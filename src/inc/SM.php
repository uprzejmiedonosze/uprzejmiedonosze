<?PHP

require_once(__DIR__ . '/JSONObject.php');

/**
 * Straz Miejska class LOL.
 * @SuppressWarnings(PHPMD.ShortClassName)
 */
class SM extends JSONObject{
    /**
     * Initites new SM from JSON.
     */
    public function __construct($json = null) {
        parent::__construct($json);
        $this->address = get_object_vars($this->address);
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

    public function getShortName(){
        $name = $this->address[0];
        $name = str_replace('Straż Miejska', 'SM', $name);
        $name = str_replace('Straż Gminna', 'SG', $name);

        $name = str_replace('Komenda Powiatowa Policji', 'KPP', $name);
        $name = str_replace('Komenda Powiatowa', 'KPP', $name);
        $name = str_replace('Komenda Miejska', 'KMP', $name);
        $name = str_replace('Komisariat Policji', 'KP', $name);
        $name = str_replace('Posterunek Policji', 'PP', $name);    
        return $name;
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

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     * @SuppressWarnings(PHPMD.CamelCaseMethodName)
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    public static function guess(object $address): string{ // straż miejska
        global $SM_ADDRESSES;
        $city = trimstr2lower($address->city);
        if($city == 'krosno' && trimstr2lower(@$address->voivodeship) == 'wielkopolskie'){
            $city = 'krosno-wlkp'; // tak, są dwa miasta o nazwie 'Krosno'...
        }
        if(array_key_exists($city, $SM_ADDRESSES)){
            $smCity = $city;
            if($city == 'warszawa' && isset($address->district)){
                if(array_key_exists($address->district, ODDZIALY_TERENOWE)){
                    $smCity = ODDZIALY_TERENOWE[$address->district];
                }
            }
            return $smCity;
        }
        $smCity = '_nieznane';
        return $smCity;
    }

}

