<?PHP

require_once(__DIR__ . '/utils.php');
require_once(__DIR__ . '/JSONObject.php');

/**
 * User class.
 */
class User extends JSONObject{

    /**
     * Creates a new User or initiate it from JSON.
     */
    public function __construct($json = null) {
        if($json){
            parent::__construct($json);
            @$this->applications = (array_values((array)$this->applications));
            return;
        }

        $this->added = date(DT_FORMAT);
        $this->data = new stdClass();
        $this->data->email = $_SESSION['user_email'];
        $this->data->name  = capitalizeName($_SESSION['user_name']);
        $this->data->exposeData = false;
        $this->applications = Array();
    }

    /**
     * Check if user having is already registered
     * (has name and address provided).
     */
    public function isRegistered(){
    	return isset($this->data) && isset($this->data->name) && isset($this->data->address);
    }

    /**
     * Connects an application with user.
     */
    public function addApplication($application){
        if(!isset($this->applications)){
            $this->applications = Array();
        }
        $this->lastLocation = $application->address->latlng;
        array_push($this->applications, $application->id);
    }

    public function getLastLocation(){
        if(isset($this->lastLocation) && $this->lastLocation != 'NaN,NaN'){
            return $this->lastLocation;
        }
        global $storage;
        if($this->hasApps()){
            $lastApp = $storage->getApplication($this->getApplicationIds()[0]);
            $this->lastLocation = $lastApp->address->latlng;
        }else{
            if(!($l = $this->guessLatLng())){
                return "52.069321,19.480311";
            }
            $this->lastLocation = $l;
        }
        $storage->saveUser($this);
        return $this->lastLocation;
    }

    private function guessLatLng(){
        if(!isset($this->data->address)){
            return null;
        }
        $address = urlencode($this->data->address);
        $ch = curl_init("https://maps.googleapis.com/maps/api/geocode/json?address=$address&key=AIzaSyC2vVIN-noxOw_7mPMvkb-AWwOk6qK1OJ8&language=pl");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        if(curl_errno($ch)){
            logger("Nie udało się pobrać danych latlng: " . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);
    
        $json = @json_decode($output, true);
        if(!json_last_error() === JSON_ERROR_NONE){
            logger("Parsowanie JSON z Google Maps APIS " . $output . " " . json_last_error_msg());
            return null;
        }
        @$latlng = $json['results'][0]['geometry']['location'];
        if(isset($latlng)){
            return $latlng['lat'] . ',' . $latlng['lng'];
        }
        return null;
    }

    /**
     * Returns an array of application ids of this user.
     */
    public function getApplicationIds(){
        return array_reverse($this->applications);
    }

    /**
     * Returns user number.
     */
    public function getNumber(){
        return $this->number;
    }

    /**
    * Super ugly function returning true for admins.
    */
    function isAdmin(){
        return $this->data->email == 'szymon@nieradka.net' || $this->data->email == 'e@nieradka.net';
    }

    /**
    * Super ugly function returning true for beta users.
    */
    function isBeta(){
        return $this->isAdmin();
    }

    /**
     * Updates current user's data.
     */
    function updateUserData($name, $msisdn, $address, $exposeData){ // , $idnumber){
        if(isset($this->added)){
            $this->updated = date(DT_FORMAT);
        }
        $this->data->name = $name;
        $this->data->sex = guess_sex_by_name($this->data->name);
        if(isset($msisdn)) $this->data->msisdn = $msisdn;
        //if(isset($idnumber)) $this->data->idnumber = $idnumber;
        $this->data->address = $address;
        $this->data->exposeData = $exposeData;
        return true;
    }

    /**
     * Returns information of this user has any apps registered.
     */
    function hasApps(){
        return count($this->applications) > 0;
    }

    /**
     * Returns (lazyloaded) sex-strings for this user.
     */
    function guessSex(){
        if(!isset($this->data->sex)){
            $this->data->sex = guess_sex_by_name($this->data->name);
        }
        return SEXSTRINGS[$this->data->sex];
    }

    /**
     * Returns user name in a 'filename' safe format.
     */
    public function getSanitizedName(){
        return mb_ereg_replace("([^\w\d])", '-', $this->data->name);
    }

    /**
     * Returns data.exposeData or false as default.
     */
    public function canExposeData(){
        if(isset($this->data->exposeData)){
            return $this->data->exposeData;
        }
        return false;
    }
}

?>