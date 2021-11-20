<?PHP
require(__DIR__ . '/NoSQLite.php');
require(__DIR__ . '/User.php');
require(__DIR__ . '/Application.php');

use \Memcache as Memcache;

/**
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ShortClassName)
 */
class DB extends NoSQLite{
    private $users;
    private $apps;
    private $recydywa;
    private $stats;

    private $loggedUser;

    /**
     * Creates DB instance with default store location.
     */
    public function __construct($store = __DIR__ . '/../../db/store.sqlite') {
        parent::__construct($store);
        $this->apps  = $this->getStore('applications');
        $this->users = $this->getStore('users');
        $this->recydywa = $this->getStore('recydywa');
        
        $this->stats = new Memcache;
        $this->stats->connect('localhost', 11211);

        try{
            $this->getCurrentUser();
        }catch(Exception $e){
            // register mode, user looged in but not registered
        }
    }

    /**
     * Returns currently logged in user or null.
     * 
     * May throw an exception if user is logged in but not registered.
     */
    public function getCurrentUser(){
        if(!isLoggedIn()){
            return $this->loggedUser = null;
        }
        if(!isset($this->loggedUser)){
            try{
                $this->loggedUser = $this->getUser(getCurrentUserEmail());
            }catch(Exception $e){
                $this->loggedUser = new User();
            }
        }
        return $this->loggedUser;
    }

    public function getNextAppNumber($email) {
        logger("getNextAppNumber $email");
        $sql = <<<SQL
            select max(json_extract(value, '$.number'))
            from applications
            where json_extract(value, '$.user.email') = :email;
        SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':email', $email);
        $stmt->execute();

        $ret = $stmt->fetch(PDO::FETCH_NUM);
        if(!isset($ret[0])) {
            return 1;
        }
        $number = extractAppNumer($ret[0]);
        return $number + 1;
    }

    public function getUserApplicationIDs($email) {
        $sql = <<<SQL
            select key
            from applications
            where json_extract(value, '$.user.email') = :email
                and json_extract(value, '$.status') not in ('ready', 'draft')
            order by json_extract(value, '$.seq') desc,
                json_extract(value, '$.added') desc
        SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':email', $email);
        $stmt->execute();

        $appIds = Array();

        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            array_push($appIds, $row[0]);
        }
        return $appIds;
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
        return $user;
    }

    public function saveUser($user){
        logger("saveUser");
        if(!isset($user->number)){
            logger("saveUser !number");
            $user->number = $this->getNextUserNumber();
        }
        $this->users->set($user->data->email, json_encode($user));
    }

    public function getNextUserNumber(){
        $sql = <<<SQL
            select max(json_extract(value, '$.number'))
            from users;
        SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $ret = $stmt->fetch(PDO::FETCH_NUM);
        if(count($ret) == 0){
            return 1;
        }
        $number = intval($ret[0]);
        logger("getNextUserNumber $number + 1");
        return $number + 1;
    }

    public function getApplication($appId){
        if(!$appId){
            throw new Exception("Próba pobrania zgłoszenia bez podania numeru.");
        }
        $json = $this->apps->get($appId);
        if(!$json){
            throw new Exception("Próba pobrania nieistniejącego zgłoszenia $appId.");
        }
        $application = new Application($json);
        return $application;

    }

    public function saveApplication($application){
        if($application->status !== 'draft' && $application->status !== 'ready') {
            if(!$application->hasNumber()) {
                $appNumber = $this->getNextAppNumber($application->user->email);
                $application->seq = $appNumber;
                $application->number = 'UD/' . $application->user->number . '/' . $appNumber;
            }
        }

        $this->apps->set($application->id, json_encode($application));
        if(isset($application->seq)) return $application->seq;
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function countApplicationsPerPlate($plateId){
        $plateId = SQLite3::escapeString($plateId);

        $sql = <<<SQL
            select count(key) from applications 
            where json_extract(value, '$.status') not in ('archived', 'ready', 'draft')
            and json_extract(value, '$.carInfo.plateId') = '$plateId';
        SQL;
        
        return (int) $this->db->query($sql)->fetchColumn();
    }

    /**
     * Returns application stats (count per status) for current user.
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function countApplicationsStatuses(){
        $email = SQLite3::escapeString($this->getCurrentUser()->data->email);

        $sql = "select json_extract(value, '$.status') as status, count(key) as cnt from applications "
            . "where json_extract(value, '$.user.email') = '$email' "
            . "group by json_extract(value, '$.status')";
        
        $ret = $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN|PDO::FETCH_GROUP);
        return $ret;
    }

    /**
     * Calculates stats for current user;
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function getUserStats($useCache = true){

        $stats = $this->stats->get("%HOST%-stats-" . getCurrentUserEmail());
        if($useCache && $stats){
            return $stats;
        }

        $stats = $this->countApplicationsStatuses();
        
        @$confirmed   = $stats['confirmed'][0];
        @$waiting     = $stats['confirmed-waiting'][0];
        @$waitingE    = $stats['confirmed-waitingE'][0];
        @$sm          = $stats['confirmed-sm'][0];
        @$ignored     = $stats['confirmed-ignored'][0];
        @$fined       = $stats['confirmed-fined'][0];
        @$instructed  = $stats['confirmed-instructed'][0];

        $stats['active'] = $confirmed + $waiting + $waitingE + $sm + $ignored + $fined + $instructed;

        $this->stats->set("%HOST%-stats-" . getCurrentUserEmail(), $stats);
        return $stats;
    }

    /**
     * Returns all applications for current user by status.
     */
    public function getConfirmedAppsByCity($city){
        $sql = <<<SQL
        select key, value
        from applications
        where json_extract(value, '$.user.email') = :email
            and json_extract(value, '$.status') = :status
            and json_extract(value, '$.smCity') = :city
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':email', $this->getCurrentUser()->data->email);
        $stmt->bindValue(':status', 'confirmed');
        $stmt->bindValue(':city', $city);
        $stmt->execute();

        $apps = Array();
        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            $apps[$row[0]] = new Application($row[1]);
        }
        return array_reverse($apps);
    }

    public function getNextCityToSent(){
        $sql = "select json_extract(value, '$.smCity'), count(key) from applications"
        . " where json_extract(value, '$.user.email') = :email "
        . " and json_extract(value, '$.status') = :status "
        . " and json_extract(value, '$.smCity') is not null "
        . " group by json_extract(value, '$.smCity') "
        . " order by count(key) desc";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':email', $this->getCurrentUser()->data->email);
        $stmt->bindValue(':status', 'confirmed');
        $stmt->execute();

        $ret = $stmt->fetchAll();
        if(count($ret) == 0){
            return null;
        }
        return $ret[0][0];
    }

    // ADMIN area

    public function getUsers(){
        if(!isAdmin()){
            throw new Exception('Dostęp zabroniony.');
        }
        $ret = Array();
        foreach($this->users->getAll() as $email => $json){
            $ret[$email] = new User($json);
        }
        return $ret;
    }

    public function execute($sql){
        if(!isAdmin()){
            throw new Exception('Dostęp zabroniony.');
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    /**
     * Returns the amount of applications per specified $plate.
     * If there is no value in DB initializes it counting active
     * apps in the 'applications' store (lazy load).
     */
    public function getRecydywa($plate){
        $recydywa = $this->recydywa->get($plate);
        if(null == $recydywa){
            $recydywa = $this->updateRecydywa($plate);
        }
        return intval($recydywa);
    }

    /**
     * Recalculates recydywa.
     */
    public function updateRecydywa($plate){
        $recydywa = $this->countApplicationsPerPlate($plate);
        $this->recydywa->set($plate, strval($recydywa));
        return $recydywa;
    }

    /**
     * Returns number of new applications (by creation date)
     * during 30 days. 
     */
    public function getStatsAppsByDay($useCache = true){

        $stats = $this->stats->get("%HOST%-getStatsAppsByDay");
        if($useCache && $stats){
            return $stats;
        }

        $sql = <<<SQL
            select substr(json_extract(applications.value, '$.added'), 1, 10) as 'day',
                count(*) as cnt from applications
            where json_extract(applications.value, '$.status') not in ('draft', 'ready')
                and json_extract(applications.value, '$.added') < date('now')
            group by substr(json_extract(applications.value, '$.added'), 1, 10)
            order by 1 desc
            limit 30;
SQL;

        $stats = $this->db->query($sql)->fetchAll(PDO::FETCH_NUM);
        $this->stats->set("%HOST%-getStatsAppsByDay", $stats, 0, 600);
        return $stats;
    }

    /**
     * Returns number of new applications (by creation date)
     * during 12 weeks.
     */
    public function getStatsAppsByWeek($useCache = true){

        $stats = $this->stats->get("%HOST%-getStatsAppsByWeek");
        if($useCache && $stats){
            return $stats;
        }

        $sql = <<<SQL
            select min(substr(json_extract(applications.value, '$.added'), 1, 10)) as 'day',
                count(*) as cnt from applications
            where json_extract(applications.value, '$.status') not in ('draft', 'ready')
                and json_extract(applications.value, '$.added') < date('now')
            group by strftime('%W', substr(json_extract(applications.value, '$.added'), 1, 10))
            order by 1 desc
            limit 12;
SQL;

        $stats = $this->db->query($sql)->fetchAll(PDO::FETCH_NUM);
        $this->stats->set("%HOST%-getStatsAppsByWeek", $stats, 0, 600);
        return $stats;
    }

    /**
     * Returns number of new applications (by creation date)
     * during 30 days. 
     */
    public function getStatsByDay($useCache = true){

        $stats = $this->stats->get("%HOST%-getStatsByDay");
        if($useCache && $stats){
            return $stats;
        }

        $today = (date('H') < 12)? "and json_extract(applications.value, '$.added') < date('now')": "";

        $sql = <<<SQL
            select substr(json_extract(applications.value, '$.added'), 1, 10) as 'day',
                count(*) as acnt,
                u.cnt as ucnt
            from applications
            left outer join (
                select substr(json_extract(users.value, '$.added'), 1, 10) as 'day',
                    count(*) as cnt
                from users
                group by  substr(json_extract(users.value, '$.added'), 1, 10)
                order by 1 desc
                limit 35
            ) u on substr(json_extract(applications.value, '$.added'), 1, 10) = u.day
            where json_extract(applications.value, '$.status') not in ('draft', 'ready')
                $today
            group by substr(json_extract(applications.value, '$.added'), 1, 10)
            order by 1 desc
            limit 30;
SQL;

        $stats = $this->db->query($sql)->fetchAll(PDO::FETCH_NUM);
        $this->stats->set("%HOST%-getStatsByDay", $stats, 0, 600);
        return $stats;
    }

    /**
     * Returns number of new applications (by creation month)
     * in last year.
     */
    public function getStatsByYear($useCache = true){

        $stats = $this->stats->get("%HOST%-getStatsByYear");
        if($useCache && $stats){
            return $stats;
        }

        $sql = <<<SQL
         select min(substr(json_extract(applications.value, '$.added'), 1, 7)) as 'day',
                count(*) as acnt,
                u.cnt as ucnt
            from applications
            left outer join (
                select min(substr(json_extract(users.value, '$.added'), 1, 7)) as 'day',
                    count(*) as cnt
                from users
                where substr(json_extract(users.value, '$.added'), 1, 4) > 2017
                group by substr(json_extract(users.value, '$.added'), 1, 7)
                order by 1 desc
                limit 35
            ) u on substr(json_extract(applications.value, '$.added'), 1, 7) = u.day
            where json_extract(applications.value, '$.status') not in ('draft', 'ready')
                and substr(json_extract(applications.value, '$.added'), 1, 4) > 2017
            group by substr(json_extract(applications.value, '$.added'), 1, 7)
            order by 1 desc
            limit 24;
SQL;

        $stats = $this->db->query($sql)->fetchAll(PDO::FETCH_NUM);
        $this->stats->set("%HOST%-getStatsByYear", $stats, 0, 600);
        return $stats;
    }

    /**
     * Returns number of applications per city.
     */
    public function getStatsAppsByCity($useCache = true){
        $stats = $this->stats->get("%HOST%-getStatsAppsByCity");
        if($useCache && $stats){
            return $stats;
        }

        $sql = "select json_extract(applications.value, '$.address.city') as city, "
            . "count(*) as cnt "
            . "from applications "
            . "where json_extract(applications.value, '$.status') not in ('draft', 'ready') "
            . "group by json_extract(applications.value, '$.address.city') "
            . "order by 2 desc, 1 limit 10 ";

        $stats = $this->db->query($sql)->fetchAll(PDO::FETCH_NUM);
        $this->stats->set("%HOST%-getStatsAppsByCity", $stats, 0, 600);
        return $stats;
    }

    /**
     * Returns all gallery applications awaiting moderation.
     */
    public function getGalleryModerationApps(){
        if(!isAdmin()){
            throw new Exception('Dostęp zabroniony.');
        }

		  $sql = <<<SQL
				select key, value 
            from applications
            where json_extract(value, '$.status') not in ('draft', 'ready', 'archived')
            and json_extract(value, '$.statements.gallery') is true
            and json_extract(value, '$.addedToGallery') is null
            order by json_extract(value, '$.added') desc;
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $apps = Array();
        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            $apps[$row[0]] = new Application($row[1]);
        }
        return $apps;
    }

    /**
     * Returns number of applications per city.
     */
    public function getGalleryCount($useCache = true){
        $stats = $this->stats->get("%HOST%-getGalleryCount");
        if($useCache && $stats){
            return $stats;
        }

        $sql = <<<SQL
            select count(key) as cnt
            from applications
            where json_extract(value, '$.addedToGallery.state') is not null
SQL;

        $stats = intval($this->db->query($sql)->fetchColumn());
        $this->stats->set("%HOST%-getGalleryCount", $stats, 0, 600);
        return $stats;
    }

    /**
     * Returns number of applications per city.
     */
    public function getGalleryByCity($useCache = true){
        $stats = $this->stats->get("%HOST%-getGalleryByCity");
        if($useCache && $stats){
            return $stats;
        }

        $sql = <<<SQL
            select json_extract(value, '$.address.city') as city,
                count(key) as cnt
            from applications
            where json_extract(value, '$.addedToGallery.state') is not null
                and  json_extract(value, '$.address.city') != ''
            group by json_extract(value, '$.address.city')
            order by 2 desc
            limit 10
SQL;

        $stats = $this->db->query($sql)->fetchAll(PDO::FETCH_NUM);
        $this->stats->set("%HOST%-getGalleryByCity", $stats, 0, 600);
        return $stats;
    }

    /**
     * Returns number of applications per city.
     */
    public function getStatsByCarBrand($useCache = true){
        $stats = $this->stats->get("%HOST%-getStatsByCarBrand");
        if($useCache && $stats){
            return $stats;
        }

        $sql = <<<SQL
            select json_extract(value, '$.carInfo.brand') as city,
                count(key) as cnt
            from applications
            where json_extract(value, '$.status') not in ('draft', 'ready')
                and json_extract(value, '$.carInfo.brand') is not null
            group by json_extract(value, '$.carInfo.brand')
            order by 2 desc
            limit 10
SQL;

        $stats = $this->db->query($sql)->fetchAll(PDO::FETCH_NUM);
        $this->stats->set("%HOST%-getStatsByCarBrand", $stats, 0, 600);
        return $stats;
    }

    public function getAppByNumber($number, $apiToken){
        if($apiToken !== API_TOKEN){
            throw new Exception('Dostęp zabroniony.');
        }
        $sql = <<<SQL
            select value 
            from applications
            where lower(json_extract(value, '$.number'))  = lower(:number)
            limit 1;
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':number', $number);
        $stmt->execute();

        return new Application($stmt->fetch(\PDO::FETCH_NUM)[0]);
    }

    public function getUserByName($name, $apiToken){
        if($apiToken !== API_TOKEN){
            throw new Exception('Dostęp zabroniony.');
        }
        $sql = <<<SQL
            select key, value 
            from users
            where lower(json_extract(value, '$.data')) like '%' || lower(:name) || '%'
            limit 10;
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':name', $name);
        $stmt->execute();

        $users = Array();
        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            $users[$row[0]] = new User($row[1]);
        }
        return $users;
    }
}

?>
