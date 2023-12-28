<?PHP

require_once(__DIR__ . '/utils.php');
require_once(__DIR__ . '/JSONObject.php');
use \stdClass as stdClass;

/**
 * User class.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class User extends JSONObject{

    /**
     * Creates a new User or initiate it from JSON.
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function __construct($json = null) {
        if($json){
            parent::__construct($json);
            if (!isset($this->appsCount))
                @$this->appsCount = sizeof(array_values((array)$this->applications));
            unset($this->applications);
            return;
        }

        $this->added = date(DT_FORMAT);
        $this->data = new stdClass();
        $this->data->email = $_SESSION['user_email'] ?? '';
        $this->data->name  = capitalizeName($_SESSION['user_name'] ?? '');
        $this->data->exposeData = false;
        $this->data->stopAgresji = false;
        $this->data->autoSend = true;
        $this->appsCount = 0;
    }

    public static function withFirebaseUser($firebaseUser) {
        $instance = new self();
        $instance->data->email = $firebaseUser['user_email'];
        $instance->data->name = $firebaseUser['user_name'];
        return $instance;
    }

    /**
     * Check if user having is already registered
     * (has name and address provided).
     */
    public function isRegistered(){
    	return isset($this->data) && !empty($this->data->name) && !empty($this->data->address);
    }

    public function setLastLocation($latlng){
        $this->lastLocation = $latlng;
    }

    public function getLastLocation(){
        if(isset($this->lastLocation) && $this->lastLocation != 'NaN,NaN'){
            return $this->lastLocation;
        }
        $lastLocation = $this->guessLatLng();
        if(!$lastLocation){
            return "52.069321,19.480311";
        }
        $this->lastLocation = $lastLocation;
        global $storage;
        $storage->saveUser($this);
        return $this->lastLocation;
    }

    /**
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
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
     * Returns user number.
     */
    public function getNumber(){
        if (isset($this->number))
            return $this->number;
        return null;
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
    function updateUserData($name, $msisdn, $address, $exposeData, $stopAgresji, $autoSend, $myAppsSize){
        if(isset($this->added))
            $this->updated = date(DT_FORMAT);

        $this->data->name = capitalizeName(cleanWhiteChars($name));
        if (!preg_match("/^(\S{2,5}\s)?\S{3,20}\s[\S -]{3,40}$/i", $this->data->name))
            throw new MissingParamException('name', "Podaj pełne imię i nazwisko, bez znaków specjalnych");

        $this->data->address = str_replace(', Polska', '', cleanWhiteChars($address));
        if (!preg_match("/^.{3,50}\d.{3,40}$/i", $this->data->address))
            throw new MissingParamException('address', "Podaj adres z ulicą, numerem mieszkania i miejscowością");
    
        $this->data->sex = guess_sex_by_name($this->data->name);
        if(isset($msisdn)) $this->data->msisdn = $msisdn;
        if(isset($stopAgresji)) $this->data->stopAgresji = $stopAgresji;
        if(isset($autoSend)) $this->data->autoSend = $autoSend;
        if(isset($myAppsSize)) $this->data->myAppsSize = $myAppsSize;
        
        $this->data->address = $address;
        $this->data->exposeData = $exposeData;
        return true;
    }

    function confirmTerms() {
        $this->data->termsConfirmation = date(DT_FORMAT);
        $this->updated = date(DT_FORMAT);
    }

    function checkTermsConfirmation() {
        if (!property_exists($this->data, 'termsConfirmation')) return false;
        return $this->data->termsConfirmation > LATEST_TERMS_UPDATE;
    }

     /**
     * Returns information of this user has any apps registered.
     */
    function hasApps(){
        return $this->appsCount > 0;
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

    public function getFirstName(){
        return mb_ereg_replace("\s.*", '', $this->data->name);
    }

    public function getEmail() {
        return $this->data->email;
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

    public function stopAgresji() {
        if(isset($this->data->stopAgresji)){
            return $this->data->stopAgresji;
        }
        return false;
    }

    public function autoSend() {
        if(isset($this->data->autoSend)){
            return $this->data->autoSend;
        }
        return true;
    }

    public function myAppsSize(){
        if(isset($this->data->myAppsSize)){
            return $this->data->myAppsSize;
        }
        return 200;
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public static function pointsToUserLevel($points) {
        global $LEVELS;
        $levelsReversed = array_reverse($LEVELS, true);
        foreach($levelsReversed as $id => $level)
            if ($points >= $level->points)
                return $id;
        return 0;
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     * @SuppressWarnings(PHPMD.DevelopmentCodeFragment)
     */
    public function getUserBadges($ret) {
        global $BADGES;
        $badges = [];

        foreach ($BADGES as $badgeName => $badgeDef) {
            $matchingCategories = array_values($badgeDef['categories']);
            $_filter = function($category) use ($matchingCategories) {
                return in_array($category, $matchingCategories);
            };
            $badgeMandates = array_sum(array_filter($ret, $_filter, ARRAY_FILTER_USE_KEY));
            if ($badgeMandates >= 5) {
                array_push($badges, $badgeName);
            }
        }

        global $PATRONITE;
        if (in_array($this->getNumber(), $PATRONITE)) {
            array_push($badges, 'patron');
        }
        return $badges;
    }
}

?>