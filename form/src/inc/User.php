<?PHP

require_once(__DIR__ . '/Utils.php');
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
        $this->data->email = getCurrentUserEmail();
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
        return $this->applications;
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
    function updateUserData($name, $msisdn, $address){
    	if(isset($this->added)){
            $this->updated = date(DT_FORMAT);
        }
    	$this->data->name = $name;
    	$this->data->msisdn = $msisdn;
    	$this->data->address = $address;
    }

    /**
     * Returns information of this user has any apps registered.
     */
    function hasApps(){
        return count($this->applications) > 0;
    }    
}

?>