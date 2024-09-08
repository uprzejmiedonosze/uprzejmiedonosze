<?PHP
require(__DIR__ . '/dataclasses/NoSQLite.php');
require(__DIR__ . '/dataclasses/User.php');
require(__DIR__ . '/dataclasses/Application.php');

use \Memcache as Memcache;
use \Application as Application;
use \User as User;
use \Exception as Exception;

/**
 * @SuppressWarnings(PHPMD.ErrorControlOperator)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ShortClassName)
 * @SuppressWarnings(PHPMD.StaticAccess)
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
    public function __construct() {
        $store = __DIR__ . '/../../db/store.sqlite';

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

    private function setStats($key, $value, $timeout=24*60*60) {
        $this->stats->set("%HOST%-$key", $value, 0, $timeout);
    }

    /**
     * Returns currently logged in user or null.
     * 
     * May throw an exception if user is logged in but not registered.
     */
    public function getCurrentUser(){
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
            select max(json_extract(value, '$.seq'))
            from applications
            where email = :email;
        SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':email', $email);
        $stmt->execute();

        $ret = $stmt->fetch(PDO::FETCH_NUM);
        if(!isset($ret[0])) {
            return 1;
        }
        $number = intval($ret[0]);
        return $number + 1;
    }

    public function getUserApplications(User $user, string $status = 'all', string $search = 'all', int $limit = 0, int $offset = 0): array {
        $userEmail = $user->getEmail();
    
        $params = [':email' => $userEmail];
        
        $limitOffset = '';
        if ($limit > 0) {
            $params += [':limit' => $limit];
            $params += [':offset' => $offset];
            $limitOffset = <<<SQL
                limit :limit offset :offset
            SQL;
        }

        $whereStatus = <<<SQL
            and json_extract(value, '$.status') not in ('ready', 'draft')
        SQL;
        if ($status !== 'all') {
            $whereStatus = <<<SQL
                and json_extract(value, '$.status') = :status
            SQL;
            $params += [':status' => $status];
        }

        $whereSearch = '';
        if ($search !== 'all') {
            $whereSearch = <<<SQL
                and lower(value) like lower(:search)
            SQL;
            $params += [':search' => "%$search%"];
        }

        $sql = <<<SQL
            select value
            from applications
            where email = :email
                $whereStatus
                $whereSearch
            order by json_extract(value, '$.seq') desc,
                json_extract(value, '$.added') desc
            $limitOffset
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_FUNC,
            fn($value) => Application::withJson($value));
    }

    /**
    * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
    */
    public function getSentApplications($daysAgo=31) {
        $olderThan = date('Y-m-d\TH:i:s', strtotime("-$daysAgo days"));
        $sql = <<<SQL
            select key, value
            from applications
            where email = :email
                and json_extract(value, '$.status') in ('confirmed-waiting', 'confirmed-waitingE', 'confirmed-sm')
                and json_extract(value, '$.sent.date') < :olderThan
            order by json_extract(value, '$.seq') desc,
                json_extract(value, '$.added') desc
        SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':email', getCurrentUserEmail());
        $stmt->bindValue(':olderThan', $olderThan);
        $stmt->execute();

        $apps = Array();

        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            $apps[$row[0]] = Application::withJson($row[1]);
        }

        function _cmp($left, $right) {
            if($left->sent->to > $right->sent->to) return 1;
            if($left->sent->to < $right->sent->to) return -1;
            if($left->sent->date > $right->sent->date) return 1;
            if($left->sent->date < $right->sent->date) return -1;
            if($left->seq > $right->seq) return 1;
            if($left->seq < $right->seq) return -1;
            return 0;
        }

        usort($apps, "_cmp");

        return $apps;
    }

    /**
     * Returns user by email
     */
    public function getUser(string $email): User{
        $json = $this->users->get($email);
        if(!$json){
            throw new Exception("Próba pobrania nieistniejącego użytkownika '$email'", 404);
        }
        $user = new User($json);
        setSentryTag("userNumber", $user->getNumber() ?? 0);
        return $user;
    }

    public function saveUser(User $user): void{
        if(!isset($user->number)){
            $user->number = $this->getNextUserNumber();
        }
        $this->users->set($user->data->email, json_encode($user));
    }

    public function getNextUserNumber(): int{
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

    public function getApplication(string $appId): Application{
        if(!$appId){
            throw new Exception("Próba pobrania zgłoszenia bez podania numeru");
        }
        @setSentryTag("appId", $appId);
        $json = $this->apps->get($appId);
        if(!$json){
            throw new Exception("Próba pobrania nieistniejącego zgłoszenia '$appId'", 404);
        }
        $application = Application::withJson($json);
        return $application;
    }

    public function checkApplicationId(string $appId): bool {
        $json = $this->apps->get($appId);
        return is_string($json);
    }

    public function saveApplication(Application $application): Application{
        @setSentryTag("appId", $application->id);
        if($application->status !== 'draft' && $application->status !== 'ready') {
            if(!$application->hasNumber()) {
                $appNumber = $this->getNextAppNumber($application->user->email);
                $application->seq = $appNumber;
                $application->number = 'UD/' . $application->user->number . '/' . $appNumber;
            }
        }
        $this->apps->set($application->id, json_encode($application), $application->user->email);

        if ($application->carInfo->plateId ?? false)
            $this->stats->delete("%HOST%-getApplicationsByPlate-{$application->carInfo->plateId}");
        return $application;
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function getApplicationsByPlate(string $plateId): array|null {
        $plateId = trim(strtoupper($plateId));
        $cache = $this->stats->get("%HOST%-getApplicationsByPlate-$plateId");
        if($cache){
            return $cache;
        }

        $plateId = SQLite3::escapeString($plateId);

        $sql = <<<SQL
            select value
            from applications 
            where json_extract(value, '$.status') not in ('archived', 'ready', 'draft')
            and json_extract(value, '$.carInfo.plateId') = :plateId;
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':plateId', $plateId);
        $stmt->execute();

        $apps = $stmt->fetchAll(PDO::FETCH_FUNC,
            fn($value) => Application::withJson($value));

        $this->setStats("getApplicationsByPlate-$plateId", $apps);
        return $apps;
    }

    /**
     * Returns application stats (count per status) for current user.
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
     */
    private function countApplicationsStatuses(string $userEmail): Array{
        $email = SQLite3::escapeString($userEmail);

        $sql = <<<SQL
            select json_extract(value, '$.status') as status,
                count(key) as cnt
            from applications
            where email = :email
            group by json_extract(value, '$.status')
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':email', $email);
        $stmt->execute();
        
        $ret = $stmt->fetchAll(PDO::FETCH_COLUMN|PDO::FETCH_GROUP);
        return array_map(function ($status) { return $status[0]; }, $ret);
    }

    /**
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    private function countUserPoints(User $user): Array{
        $userEmail = $user->getEmail();
        $email = SQLite3::escapeString($userEmail);

        $sql = <<<SQL
            select
                cast(json_extract(value, '$.category') as integer) as category,
                count(key) as cnt
            from applications
            where email = :email
                and json_extract(value, '$.status') in ('confirmed-fined')
            group by 1
            order by 1;
        SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':email', $email);
        $stmt->execute();
        
        $ret = $stmt->fetchAll(PDO::FETCH_COLUMN|PDO::FETCH_GROUP);
        $ret = array_map(function ($item) { return $item[0]; }, $ret);

        $points = $ret;
        $mandates = $ret;

        array_walk($points, function(&$item, $key) { GLOBAL $CATEGORIES; $item = $item * $CATEGORIES[$key]->getPoints(); });
        array_walk($mandates, function(&$item, $key) { GLOBAL $CATEGORIES; $item = $item * $CATEGORIES[$key]->getMandate(); });

        $mandates = array_sum($mandates);
        $points = array_sum($points);
        $level = $user->pointsToUserLevel($points);
        $badges = $user->getUserBadges($ret);

        return Array(
            "mandates" => $mandates,
            "points" => $points,
            "level" => $level,
            "badges" => $badges
        );
    }

    /**
     * Calculates stats for current user;
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    public function getUserStats(bool $useCache, User $user): Array{
        $userEmail = $user->getEmail();

        $stats = $this->stats->get("%HOST%-stats3-$userEmail");
        if($useCache && $stats){
            return $stats;
        }

        $stats = $this->countApplicationsStatuses($userEmail);
        $stats['active'] = array_sum($stats) - @$stats['archived'] - @$stats['draft'];

        $userPoints = $this->countUserPoints($user);
        $stats = $stats + $userPoints;

        $this->setStats("stats3-$userEmail", $stats, 0);
        return $stats;
    }

    /**
     * Returns all applications for current user by status.
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function getConfirmedAppsByCity(string $city): Array{
        $sql = <<<SQL
        select key, value
        from applications
        where email = :email
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
            $apps[$row[0]] = Application::withJson($row[1]);
        }
        return array_reverse($apps);
    }

    public function getNextCityToSent(){
        $sql = <<<SQL
            select json_extract(value, '$.smCity'),
                count(key) from applications
            where email = :email
                and json_extract(value, '$.status') = :status
                and json_extract(value, '$.smCity') is not null
            group by json_extract(value, '$.smCity')
            order by count(key) desc
        SQL;

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

    public function execute($sql){
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt;
    }

    /**
     * Returns the amount of applications per specified $plate.
     * If there is no value in DB initializes it counting active
     * apps in the 'applications' store (lazy load).
     */
    public function getRecydywa(string $plate): int{
        $recydywa = $this->recydywa->get($plate);
        if(null == $recydywa){
            $recydywa = $this->updateRecydywa($plate);
        }
        return intval($recydywa);
    }

    /**
     * Recalculates recydywa.
     */
    public function updateRecydywa(string $plate): int{
        $recydywa = count($this->getApplicationsByPlate($plate));
        $this->recydywa->set($plate, strval($recydywa));
        return $recydywa;
    }

    /**
     * Returns number of new applications (by creation date)
     * during 30 days. 
     */
    public function getStatsAppsByDay(bool $useCache=true){

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
        $this->setStats('getStatsAppsByDay', $stats);
        return $stats;
    }

    /**
     * Returns number of new applications (by creation date)
     * during 12 weeks.
     */
    public function getStatsAppsByWeek(bool $useCache=true) {

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
        $this->setStats('getStatsAppsByWeek', $stats);
        return $stats;
    }

    /**
     * Returns number of new applications (by creation date)
     * during 30 days. 
     */
    public function getStatsByDay(bool $useCache=true){

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
        $this->setStats('getStatsByDay', $stats);
        return $stats;
    }

    /**
     * Returns number of new applications (by creation month)
     * in last year.
     */
    public function getStatsByYear(bool $useCache=true){

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
        $this->setStats('getStatsByYear', $stats);
        return $stats;
    }

    /**
     * Returns number of applications per city.
     */
    public function getStatsAppsByCity(bool $useCache=true){
        $stats = $this->stats->get("%HOST%-getStatsAppsByCity");
        if($useCache && $stats){
            return $stats;
        }

        $sql = <<<SQL
            select json_extract(value, '$.address.city') as city,
                count(key) as cnt
            from applications
            where json_extract(value, '$.status') not in ('draft', 'ready')
            group by json_extract(value, '$.address.city')
            order by 2 desc, 1 limit 10
        SQL;

        $stats = $this->db->query($sql)->fetchAll(PDO::FETCH_NUM);
        $this->setStats('getStatsAppsByCity', $stats);
        return $stats;
    }

    /**
     * Returns all gallery applications awaiting moderation.
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function getGalleryModerationApps(){
        if(!$this->getCurrentUser()->isModerator()){
            throw new Exception('Dostęp zabroniony');
        }

		  $sql = <<<SQL
            select key, value 
            from applications
            where json_extract(value, '$.status') not in ('draft', 'ready', 'archived')
            and json_extract(value, '$.statements.gallery') is true
            and json_extract(value, '$.addedToGallery') is null
            order by json_extract(value, '$.added') desc
            limit 300;
        SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $apps = Array();
        while ($row = $stmt->fetch(\PDO::FETCH_NUM, \PDO::FETCH_ORI_NEXT)) {
            $apps[$row[0]] = Application::withJson($row[1]);
        }
        return $apps;
    }

    /**
     * Returns number of applications per city.
     */
    public function getGalleryCount(bool $useCache=true): int{
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
        $this->setStats('getGalleryCount', $stats);
        return $stats;
    }

    /**
     * Returns number of applications per city.
     */
    public function getGalleryByCity(bool $useCache=true){
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
        $this->setStats('getGalleryByCity', $stats);
        return $stats;
    }

    /**
     * Returns number of applications per city.
     */
    public function getStatsByCarBrand(bool $useCache=true){
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
        $this->setStats('getStatsByCarBrand', $stats);
        return $stats;
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public function getMainPageStats(bool $useCache=true): array{
        $stats = $this->stats->get("%HOST%-getMainPageStats");
        if($useCache && $stats){
            return $stats;
        }

        global $SM_ADDRESSES;
        $sm = count($SM_ADDRESSES);

        $sql = <<<SQL
            select count(key) as cnt
            from applications
            where json_extract(value, '$.status') not in ('ready', 'draft', 'archive')
        SQL;
        $apps = intval($this->db->query($sql)->fetchColumn());

        $sql = <<<SQL
            select count(key) as cnt
            from users
        SQL;
        $users = intval($this->db->query($sql)->fetchColumn());

        global $PATRONITE;
        $patrons = count($PATRONITE->active);

        $stats = Array('apps' => $apps, 'users' => $users, 'sm' => $sm, 'patrons' => $patrons);
        $this->setStats('getMainPageStats', $stats);
        return $stats;
    }

    public function getAppByNumber($number, $apiToken){
        if($apiToken !== API_TOKEN){
            throw new Exception('Dostęp zabroniony');
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

        return Application::withJson($stmt->fetch(\PDO::FETCH_NUM)[0]);
    }

    public function getUserByName($name, $apiToken){
        if($apiToken !== API_TOKEN){
            throw new Exception('Dostęp zabroniony');
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
