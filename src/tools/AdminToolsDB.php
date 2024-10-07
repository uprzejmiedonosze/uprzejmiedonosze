<?PHP

require_once(__DIR__ . '/../../vendor/autoload.php');
require(__DIR__ . '/../inc/include.php');


/**
 * @SuppressWarnings(PHPMD)
 */
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
    public function removeDrafts($olderThan=10, $dryRun=true){ // days
        $this->removeAppsByStatus($olderThan, 'draft', $dryRun);
    }

    /**
    * Removes old apps in ready status and it's files.
    */
    public function removeReadyApps($olderThan = 30, $dryRun=true){
        $this->removeAppsByStatus($olderThan, 'ready', $dryRun);
    }

    /**
     * Removes user by given $email
     * @SuppressWarnings(PHPMD.MissingImport)
     */
    public function removeUser($email, $dryRun=true){
        if(!isset($email)){
            throw new Exception("No email provided\n");
        }
    
        $email = SQLite3::escapeString($email);
    
        $userJson = $this->getStore('users')->get($email);
        if(!$userJson){
            throw new Exception("Trying to remove nonesiting user: $email");
        }
        $user = new User($userJson);

        $apps = $this->getAllApplicationsByEmail($email);
    
        echo "Usuwam wszystkie zgłoszenia użytkownika '$email'\n";
        foreach($apps as $app){
            $this->removeApplication($app, $dryRun);
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

    /**
     * Removes application
     */
    private function removeApplication($app, $dryRun){
        global $STATUSES;
        $added = (isset($app->added))? " dodane {$app->added}": "";
        $number = (isset($app->number))? "{$app->number} ($app->id)": "($app->id)";
        $status = $STATUSES[$app->status]->name;
        $email = (isset($app->user->email))? " użytkownika {$app->user->email}": " użytkownika @anonim";
        echo "Usuwam zgłoszenie numer $number [$status]$email$added\n";
        if(isset($app->carImage)){
            $this->removeFile($app->carImage->url, $dryRun);
            $this->removeFile($app->carImage->thumb, $dryRun);
        }
        if(isset($app->contextImage)){
            $this->removeFile($app->contextImage->url, $dryRun);
            $this->removeFile($app->contextImage->thumb, $dryRun);
        }
        if(isset($app->carInfo) && isset($app->carInfo->plateImage)){
            $this->removeFile($app->carInfo->plateImage, $dryRun);
        }

        echo " zgłoszenie oraz jego pliki usunięte;\n\n";
        if($dryRun){
            return;
        }
        $this->getStore('applications')->delete($app->id);
    }

    private function removeFile($fileName, $dryRun){
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
     * @SuppressWarnings(PHPMD.MissingImport)
     */
    private function removeAppsByStatus($olderThan, $status, $dryRun){ // days
        if($status !== 'draft' && $status !== 'ready'){
            throw new Exception("Refuse to remove apps in '$status' status.");
        }

        $apps = $this->getAllApplicationsByStatus($status);

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
            $this->removeApplication($app, $dryRun);
        }
    }

    /**
     * Returns all applications by status.
     * @SuppressWarnings(PHPMD.MissingImport)
     */
    private function getAllApplicationsByStatus($status){
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
            $apps[$row[0]] = Application::withJson($row[1]);
        }
        return $apps;
    }

    /**
     * @SuppressWarnings(PHPMD.MissingImport)
     */
    private function getAllUsers() {
        $sql = <<<SQL
            select key, value
            from users;
        SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $users = Array();

        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            $users[$row[0]] = new User($row[1]);
        }
        return $users;
    }

    /**
     * Returns all applications by user.
     * 
     * @email
     * @onlyWithNumber - ignore drafts and ready apps
     * @SuppressWarnings(PHPMD.MissingImport)
     */
    private function getAllApplicationsByEmail($email, $onlyWithNumber = null){

        $onlyWithNumberSQL = ($onlyWithNumber)? " and json_extract(value, '$.status') not in ('ready', 'draft')": "";

        $sql = <<<SQL
            select key, value
            from applications
            where json_extract(value, '$.user.email') = :email $onlyWithNumberSQL;
        SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':email', $email);
        $stmt->execute();

        $apps = Array();

        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            $apps[$row[0]] = Application::withJson($row[1]);
        }
        return $apps;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function upgradeAllApps($version, $dryRun){
        $users = $this->getAllUsers();
        foreach ($users as $email => $user) {
            echo date(DT_FORMAT) . " migrating user $email:\n";
            if(!$dryRun){
                $this->getStore('users')->set($email, json_encode($user));
            }
            $apps = $this->getAllApplicationsByEmail($email, false);
            foreach ($apps as $appId => $app) {
                $this->updateApp($app, $version, $dryRun);
            }
        }
    }

    private function updateApp($app, $version, $dryRun) {
        global $STATUSES;
        $added = (isset($app->added))? " added on {$app->added}": "";
        $number = (isset($app->number))? "{$app->number} ($app->id)": "($app->id)";
        $status = $STATUSES[$app->status]->name;
        echo "  - migrating app $number [$status] by {$app->user->email}$added\n";
        if($app->version < '2.1.0') {
            $app->inexactHour = true;
        }
        $app->version = $version;

        if($dryRun){
            return;
        }
        $this->getStore('applications')->set($app->id, json_encode($app));
    }

    public function refreshRecydywa() {
        $sql = <<<SQL
            select json_extract(value, '$.carInfo.plateId') as plateId,
                count(key) as appsCnt,
                count(distinct email) as usersCnt,
                count(distinct json_extract(value, '$.address.city')) as citiesCnt
            from applications 
            where json_extract(value, '$.status') not in ('archived', 'ready', 'draft')
            group by 1;
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $recydywa = $this->getStore('recydywa');
        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            $plateId = trim(strtoupper($row[0]));
            echo "$plateId set\n";
            $rec = Recydywa::withValues($row[1], $row[2], $row[3]);
            $recydywa->set("$plateId v2", json_encode($rec));
        }
    }
}

$db = new AdminToolsDB();

$db->removeDrafts(10, false);
$db->removeReadyApps(30, false);

// $db->removeUser('szymon@nieradka.net', false);

// $db->upgradeAllApps('2.3.0', false);