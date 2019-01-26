<?PHP
require(__DIR__ . '/NoSQLite.php');
require(__DIR__ . '/User.php');
require(__DIR__ . '/Application.php');

class DB extends NoSQLite{
    private $users;
    private $apps;

    private $loggedUser;

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
        if(!isLoggedIn()){
            return $this->loggedUser = null;
        }
        if(!isset($this->loggedUser)){
            $this->loggedUser = $this->getUser(getCurrentUserEmail());
        }
        return $this->loggedUser;
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
        return $application;

    }

    public function saveApplication($application){
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

?>