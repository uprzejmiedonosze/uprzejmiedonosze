<?PHP

require(__DIR__ . '/NoSQLite.php');

class DB extends NoSQLite{
    private $users;
    private $apps;

    private $loggedUser;

    // USERS

    /**
     * Creates DB instance with default store location.
     */
    public function __construct($store = __DIR__ . '/../db/store.sqlite') {
        parent::__construct($store);
        $this->apps  = $this->getStore('applications');
        $this->users = $this->getStore('users');
        
        $this->getCurrentUser();
    }

    /**
     * Returns currently logged in user or null.
     */
    public function getCurrentUser(){
        if(!$this->isLoggedIn()){
            return $this->loggedUser = null;
        }
        if(!isset($this->loggedUser)){
            $this->loggedUser = $this->getUser($this->getCurrentUserEmail());
        }
        return $this->loggedUser;
    }

    public function isLoggedIn(){
        return isset($_SESSION['token']) && verifyToken($_SESSION['token']);
    }

    public function checkIfLogged(){
        if(!$this->isLoggedIn()){
            redirect("login.html?next=" . $_SERVER['REQUEST_URI']);
        }
    }

    public function getCurrentUserEmail(){
        if(!empty($_SESSION['user_email'])){
            return $_SESSION['user_email'];
        }
        throw new Exception("Próba pobrania danych niezalogowanego użytkownika");
    }

    /**
     * Returns user by email
     */
    public function getUser($email){
        $json = $this->users->get($email);
        if(!$json){
            throw new Exception("Próba pobrania nieistniejącego użytkownika $email.");
        }
        $user = new User($json);
        @$user->applications = (array_values((array)$user->applications));
        return $user;
    }

    public function saveUser($user){
        if(!isset($user->number)){
            $user->number = $this->countUsers() + 1;
        }
        $this->users->set($user->data->email, json_encode($user));
    }

    public function countUsers(){
        return count($this->users);
    }

    public function getUsers(){
        if(!$this->getCurrentUser()->isAdmin()){
            throw new Exception('Dostęp zabroniony.');
        }
        $ret = Array();
        foreach($this->users->getAll() as $email => $json){
            $ret[$email] = new User($json);
        }
        return $ret;
    }

    public function getApplication($appId){
        $json = $this->apps->get($appId);
        if(!$json){
            throw new Exception("Próba pobrania nieistniejącego zgłoszenia $appId.");
        }
        $application = new Application($json);
        logger("getApplication " . print_r($application, true));
        return $application;

    }

    public function saveApplication($application){
        logger("saveApplication " . print_r($application, true));
        $this->apps->set($application->id, json_encode($application));
    }

    public function countApplicationsPerPlate($plateId){
        $plateId = SQLite3::escapeString($plateId);

        $sql = "select count(*) from applications "
            . "where (json_extract(value, '$.status') = 'archived' "
            . "or json_extract(value, '$.status') like 'confirmed%') "
            . "and json_extract(value, '$.carInfo.plateId') = '$plateId';";
        
        return (int) $this->db->query($sql)->fetchColumn();
    }

}

$storage = new DB();

class JSONObject {
    public function __construct($json = null) {
        logger("JSONObject::__construct");
        if($json){
            logger("JSONObject::__construct with json");
            $this->set(json_decode($json, true));
        }
    }

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
}

class User extends JSONObject{
    public function __construct($json = null) {
        if($json){
            parent::__construct($json);
            @$this->applications = (array_values((array)$this->applications));
        }else{
            global $storage;

            $this->added = date(DT_FORMAT);
            $this->data = new stdClass();
            $this->data->email = $storage->getCurrentUserEmail();
            $this->applications = Array();
        }
    }

    /**
     * Check if user having is already registered
     * (has name and address provided).
     * 
     * Returns:
     *   boolean
     */
    public function isRegistered(){
    	return isset($this->data) && isset($this->data->name) && isset($this->data->address);
    }

    public function addApplication($application){
        if(!isset($this->applications)){
            $this->applications = Array();
        }
        array_push($this->applications, $application->id);
    }

    public function getApplicationIds(){
        return $this->applications;
    }

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

    function hasApps(){
        return count($this->applications) > 0;
    }    
}

class Application extends JSONObject{
    public function __construct($json = null) {
        logger("Application::__construct");
        if($json){
            logger("Application::__construct with json");
            parent::__construct($json);
        }else{
            logger("Application::__construct no json");
            global $storage;

            $this->id = guidv4();
            $this->added = date(DT_FORMAT);
            $this->user = $storage->getCurrentUser()->data;
            $this->status = 'draft';
        }
    }

    public function getDate(){
        return (new DateTime($this->date))->format('Y-m-d');
    }

    public function getTime(){
        return (new DateTime($this->date))->format('H:i');
    }

}

?>