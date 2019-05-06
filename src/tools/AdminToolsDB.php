<?PHP

require_once(__DIR__ . '/../inc/NoSQLite.php');
require_once(__DIR__ . '/../inc/Application.php');
require_once(__DIR__ . '/../inc/config.php');

class AdminToolsDB extends NoSQLite{
    /**
     * Creates DB instance with default store location.
     */
    public function __construct($store = __DIR__ . '/../db/store.sqlite') {
        parent::__construct($store);
    }

    /**
    * Removes old drafts and it's files.
    */
    public function removeDrafts($olderThan = 10){ // days
        $this->_removeAppsByStatus($olderThan, 'draft');
    }

    /**
    * Removes old apps in ready status and it's files.
    */
    public function removeReadyApps($olderThan = 30){
        $this->_removeAppsByStatus($olderThan, 'ready');
    }

    /**
     * Removes user by given $email
     */
    public function removeUser($email){
        if(!isset($email)){
            throw new Exception('No email provided');
        }
    
        $email = SQLite3::escapeString($email);
    
        try{
            $user = $this->db->getStore('users')->get($email);
        }catch(Exception $e){
            throw new Exception("Trying to remove nonesiting user: $email");
        }

        $apps = $this->_getAllApplicationsByEmail($email);
    
        echo "Usuwam wszytkie zgłoszenia użytkownika '$email'\n";
        foreach($apps as $app){
            $this->_removeApplication($app);
        }
    
        echo "Zamazuję dane użytkownika w bazie\n";
        // adding empty user under a different key
        $time = date(DT_FORMAT);
        $user->data->name = 'DELETED';
        $user->data->msisdn = 'DELETED';
        $user->data->address = 'DELETED';
        $user->data->email = md5($email . $time);
        $user->data->emailMD5 = md5($email);
    
        $user->deleted = $time;
        $user->applications = Array();
        $this->db->getStore('users')->set($user->data->email, json_encode($user));
    
        // removing old user
        $this->db->getStore('users')->delete($email);
    }

    //////////////////////// GENERICS ////////////////////////

    /**
     * Removes application
     */
    private function _removeApplication($app, $dryRun = true){
        echo "Usuwam zgłoszenie $app->id użytkownika {$app->user->email} dodane {$app->added}\n";
        if(isset($app->carImage)){
            $this->_removeFile($app->carImage->url, $dryRun);
            $this->_removeFile($app->carImage->thumb, $dryRun);
        }
        if(isset($app->contextImage)){
            $this->_removeFile($app->contextImage->url, $dryRun);
            $this->_removeFile($app->contextImage->thumb, $dryRun);
        }
        if(isset($app->carInfo)){
            $this->_removeFile($app->carInfo->plateImage, $dryRun);
        }

        if(preg_match('/^.?cdn2\//', $app->carImage->url)){
        }

        $this->db->getStore('applications')->delete($app->id);
        echo " - zgłoszenie oraz jego pliki usunięte;";
    }

    private function _removeFile($fileName, $dryRun = true){
        $file = __DIR__ . "/../$fileName";
        if(!isset($file)){
            return;
        }
        if(!file_exists($file)){
            echo " ! plik '$fileName' nie istnieje\n";
            return;
        }
        echo " - usuwam '$fileName'\n";
        if(!$dryRun){
            unlink($file);
        }
    }

    /**
    * Generic function to remove apps by status
     */
    private function _removeAppsByStatus($olderThan, $status){ // days
        if($status !== 'draft' && $status !== 'ready'){
            throw new Exception("Refuse to remove apps in '$status' status.");
        }

        $apps = $this->_getAllApplicationsByStatus($status);

        $date = date_create();
        date_sub($date, date_interval_create_from_date_string("$olderThan days"));
        $latest = date_format($date, "Y-m-d");;

        foreach($apps as $app){
            if(isset($app->added)){
                if($app->added > $latest){
                    echo "Not removing $app->id from $app->added by {$app->user->email} as it's still fresh\n";
                    continue;
                }
            }
            if($app->status !== $status) { // just for safety
                continue;
            }
            $this->_removeApplication($app);
        }
    }

    /**
     * Returns all applications by status.
     */
    private function _getAllApplicationsByStatus($status){
        $sql = <<<SQL
            select value
            from applications
            where json_extract(value, '$.status') = :status;
SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', $status);
        $stmt->execute();

        $apps = Array();
        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            $apps[$row[0]] = new Application($row[1]);
        }
        return $apps;
    }

    /**
     * Returns all applications by user.
     */
    private function _getAllApplicationsByEmail($email){
        $sql = <<<SQL
            select value
            from applications
            where json_extract(value, '$.user.email') = :email;
SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':email', $email);
        $stmt->execute();

        $apps = Array();
        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            $apps[$row[0]] = new Application($row[1]);
        }
        return $apps;
    }


}

$db = new ToolsDB();

$db->removeDrafts(10, 'draft');
$db->removeDrafts(30, 'ready');

?>