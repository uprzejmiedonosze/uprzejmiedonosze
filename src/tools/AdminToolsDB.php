<?PHP

require_once(__DIR__ . '/../inc/NoSQLite.php');
require_once(__DIR__ . '/../inc/Application.php');
require_once(__DIR__ . '/../inc/User.php');
require_once(__DIR__ . '/../inc/config.php');

class AdminToolsDB extends NoSQLite{
    /**
     * Creates DB instance with default store location.
     */
    public function __construct($store = __DIR__ . '/../../db/store.sqlite') {
        parent::__construct($store);
    }

    /**
    * Removes old drafts and it's files.
    */
    public function removeDrafts($olderThan = 10, $dryRun){ // days
        $this->_removeAppsByStatus($olderThan, 'draft', $dryRun);
    }

    /**
    * Removes old apps in ready status and it's files.
    */
    public function removeReadyApps($olderThan = 30, $dryRun){
        $this->_removeAppsByStatus($olderThan, 'ready', $dryRun);
    }

    /**
     * Removes user by given $email
     */
    public function removeUser($email, $dryRun){
        if(!isset($email)){
            throw new Exception("No email provided\n");
        }
    
        $email = SQLite3::escapeString($email);
    
        $userJson = $this->getStore('users')->get($email);
        if(!$userJson){
            throw new Exception("Trying to remove nonesiting user: $email");
        }
        $user = new User($userJson);

        $apps = $this->_getAllApplicationsByEmail($email);
    
        echo "Usuwam wszytkie zgłoszenia użytkownika '$email'\n";
        foreach($apps as $app){
            $this->_removeApplication($app, $dryRun);
        }

        $cdn2UserFolder = __DIR__ . "/../../cdn2/{$user->number}/";
        if(file_exists($cdn2UserFolder) && filetype($cdn2UserFolder) == 'dir'){
            echo "Kasuję folder użytkownika\n";
            if(!$dryRun){
                $cmd = sprintf("rm -rf %s", escapeshellarg($cdn2UserFolder));
                exec($cmd, $output);
                unset($output);
            }
        }
    
        echo "Zamazuję dane użytkownika w bazie\n";
        if($dryRun){
            return;
        }
        // adding empty user under a different key
        $time = date(DT_FORMAT);
        $user->data->name = 'DELETED';
        $user->data->msisdn = 'DELETED';
        $user->data->address = 'DELETED';
        $user->data->email = md5($email . $time);
        $user->data->emailMD5 = md5($email);
    
        $user->deleted = $time;
        $user->applications = Array();
        $this->getStore('users')->set($user->data->email, json_encode($user));
    
        // removing old user
        $this->getStore('users')->delete($email);
    }

    public function upgradeAllApps($version, $dryRun){
        foreach(STATUSES as $st => $status ){
            if($status[3]){
                echo "\nMigruję zgłoszenia o statusie '{$status[0]}'\n";
                $this->upgradeAppsByStatus($version, $st, $dryRun);
            }
        }
    }

    public function upgradeAppsByStatus($version, $status, $dryRun){
        $apps = $this->_getAllApplicationsByStatus($status);
        foreach($apps as $app){
            $app->version = $version;
            $this->_migrateApplication($app, $dryRun);
        }
    }

    //////////////////////// GENERICS ////////////////////////

    /**
     * Removes application
     */
    private function _removeApplication($app, $dryRun){
        $added = (isset($app->added))? " dodane {$app->added}": "";
        $number = (isset($app->number))? "{$app->number} ($app->id)": "($app->id)";
        $status = STATUSES[$app->status][0];
        $email = (isset($app->user->email))? " użytkownika {$app->user->email}": " użytkownika @anonim";
        echo "Usuwam zgłoszenie numer $number [$status]$email$added\n";
        if(isset($app->carImage)){
            $this->_removeFile($app->carImage->url, $dryRun);
            $this->_removeFile($app->carImage->thumb, $dryRun);
        }
        if(isset($app->contextImage)){
            $this->_removeFile($app->contextImage->url, $dryRun);
            $this->_removeFile($app->contextImage->thumb, $dryRun);
        }
        if(isset($app->carInfo) && isset($app->carInfo->plateImage)){
            $this->_removeFile($app->carInfo->plateImage, $dryRun);
        }

        //if(preg_match('/^.?cdn2\//', $app->carImage->url)){
        //}

        echo " zgłoszenie oraz jego pliki usunięte;\n\n";
        if($dryRun){
            return;
        }
        $this->getStore('applications')->delete($app->id);
    }

    private function _removeFile($fileName, $dryRun){
        $file = __DIR__ . "/../../$fileName";
        if(!isset($file) || empty($fileName)){
            return;
        }
        if(!file_exists($file)){
            echo " ! plik '$fileName' nie istnieje\n";
            return;
        }
        if(filetype($file) !== 'file'){
            echo " ! '$fileName' nie jest plikiem\n";
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
    private function _removeAppsByStatus($olderThan, $status, $dryRun){ // days
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
            $this->_removeApplication($app, $dryRun);
        }
    }

    /**
     * Returns all applications by status.
     */
    private function _getAllApplicationsByStatus($status){
        $sql = <<<SQL
            select key, value
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
            select key, value
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

    /**
     * Moves files from CDN to CDN2
     */
    private function _migrateApplication($app, $dryRun){
        $added = (isset($app->added))? " dodane {$app->added}": "";
        $number = (isset($app->number))? "{$app->number} ($app->id)": "($app->id)";
        $status = STATUSES[$app->status][0];
        echo "Migruję zgłoszenie numer $number [$status] użytkownika {$app->user->email}$added\n";

        if(!isset($app->user->number)){
            if($app->user->email == 'zibi@nora.pl'){
                $app->user->email = 'tapiau@gmail.com';
            }
            $user = new User($this->getStore('users')->get($app->user->email));
            $app->user->number = $user->number;
        }
        $baseDir = 'cdn2/' . $app->user->number;

        if(!file_exists(__DIR__ . "/../../$baseDir")){
            mkdir(__DIR__ . "/../../$baseDir", 0755, true);
        }
        $baseFileName = $baseDir . '/' . $app->id;
        
        if(isset($app->carImage)){
            $this->_moveFileToCDN2($app->carImage->url, "$baseFileName,ca.jpg", $dryRun);
            $app->carImage->url = "$baseFileName,ca.jpg";
            $this->_moveFileToCDN2($app->carImage->thumb, "$baseFileName,ca,t.jpg", $dryRun);
            $app->carImage->thumb = "$baseFileName,ca,t.jpg";
        }
        if(isset($app->contextImage)){
            $this->_moveFileToCDN2($app->contextImage->url, "$baseFileName,co.jpg", $dryRun);
            $app->contextImage->url = "$baseFileName,co.jpg";
            $this->_moveFileToCDN2($app->contextImage->thumb, "$baseFileName,co,t.jpg", $dryRun);
            $app->contextImage->thumb = "$baseFileName,co,t.jpg";
        }
        if(isset($app->carInfo) && isset($app->carInfo->plateImage)){
            $this->_moveFileToCDN2($app->carInfo->plateImage, "$baseFileName,ca,p.jpg", $dryRun);
            $app->carInfo->plateImage = "$baseFileName,ca,p.jpg";
        }
        $this->_moveFileToCDN2("cdn/{$app->id}.pdf", "$baseFileName.pdf", $dryRun);

        if($dryRun){
            return;
        }
        $this->getStore('applications')->set($app->id, json_encode($app));
    }

    private function _moveFileToCDN2($from, $to, $dryRun){
        $ffile = __DIR__ . "/../../$from";
        $tfile = __DIR__ . "/../../$to";

        if(!isset($ffile) || empty($from)){
            return;
        }
        if(!file_exists($ffile)){
            echo " ! plik '$from' nie istnieje\n";
            return;
        }
        if(filetype($ffile) !== 'file'){
            echo " ! '$from' nie jest plikiem\n";
            return;
        }
        if(!preg_match('/^.?cdn2\//', $from)){
            echo " - przenoszę '$from' do '$tfile'\n";
            if(!$dryRun){
                rename($ffile, $tfile);
            }    
        }
    }

}

$db = new AdminToolsDB();

$db->removeDrafts(10, false);
$db->removeReadyApps(30, false);

//$db->removeUser('szymon@nieradka.net', false);

$db->upgradeAllApps('1.0.1', false);
?>