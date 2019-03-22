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
        $this->data->name  = capitalizeSentence($_SESSION['user_name'], true);
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
        array_push($this->applications, $application->id);
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
        return $this->data->email == 'szymon@nieradka.net' || $this->data->email == 'szymon.nieradka@polidea.com';
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