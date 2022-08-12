<?PHP

require_once(__DIR__ . '/utils.php');
require_once(__DIR__ . '/JSONObject.php');
require_once(__DIR__ . '/integrations/CityAPI.php');
use \Datetime as Datetime;
use \Exception as Exception;
use \JSONObject as JSONObject;

/**
 * Application class.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Application extends JSONObject{
    /**
     * Creates new Application of initites it from JSON.
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public function __construct($json = null) {
        if($json){
            parent::__construct($json);
            @$this->statusHistory = (array)$this->statusHistory;
            @$this->comments = (array)$this->comments;
            if(!isset($this->seq) && $this->hasNumber()) {
                $this->seq = extractAppNumer($this->getNumber());
            }
            $this->migrateSent();
            return;
        }
        global $storage;
        $user = $storage->getCurrentUser();

        $this->date = null;
        $this->id = genSafeId();
        $this->added = date(DT_FORMAT);
        $this->user = $user->data;
        $this->user->number = $user->getNumber();
        $this->user->sex = guess_sex_by_name($this->user->name);
        $this->status = 'draft';
        $this->category = 0;
        $this->initStatements();
        $this->version = '2.0.1';

        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        $this->browser = get_browser($userAgent, true);
        $this->browser['user_agent'] = $userAgent;
    }

    /**
     * @SuppressWarnings("unused")
     */
    private function migrateSent() {
        if (isset($this->sent) || in_array($this->status, ['draft', 'ready', 'confirmed'])) {
            return;
        }
        $this->sent = new JSONObject();
        $smData = $this->guessSMData();
        $sentOn = array_filter($this->statusHistory, function($entry, $key) {
            return isset($entry->new) && ($entry->new == 'confirmed-waiting' || $entry->new == 'confirmed-waitingE');
        }, ARRAY_FILTER_USE_BOTH);
        $this->sent->date = null;
        if(sizeof($sentOn) > 0) {
            $this->sent->date = array_key_first($sentOn);
        }
        if (isset($this->sentViaAPI)) {
            $this->sent->subject = $this->getEmailSubject();
            $this->sent->to = "fixmycity";
            $this->sent->method = $smData->api;
            return;
        }
        if (isset($this->sentViaMail)) {
            $this->sent->date = $this->sentViaMail->date;
            $this->sent->subject = $this->sentViaMail->subject;
            $this->sent->to = $this->sentViaMail->to;
            $this->sent->method = $smData->api;
            return;
        }
        $this->sent->subject = $this->getEmailSubject();
        $this->sent->to = $smData->getEmail();
        $this->sent->method = 'manual';
    }

    public function initStatements(){
        if(isset($this->statements)){
            return;
        }
        $this->statements = new JSONObject();
        $this->statements->witness = false;
        $this->statements->gallery = false;
        $this->statements->hideNameInPdf = true;
    }

    /**
     * Returns application date in Y-m-d format.
     */
    public function getDate(){
        return (new DateTime($this->date))->format('Y-m-d');
    }

    /**
     * Returns date in "January 2017" format.
     */
    public function getMonthYear(){
        $date = new DateTime($this->date);
        $months = [
            'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec',
            'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'
        ];
        return $months[intval($date->format('m')) - 1] . ' '. $date->format('Y');
    }

    /**
     * Returns application time in H:i format.
     */
    public function getTime(){
        $format = 'H:i'; // 24-hour format of an hour with leading zeros : Minutes with leading zeros
        if(isset($this->dtFromPicture) && !$this->dtFromPicture){
            $format = 'G:00'; // 24-hour format of an hour without leading zeros
        }
        return (new DateTime($this->date))->format($format);
    }

    /**
     * Returns application number (UD/X/Y)
     */
    public function getNumber(){
        return $this->hasNumber() ? $this->number : null;
    }

    /**
     * Returns (lazy initialized) User number.
     */
    public function getUserNumber(){
        if(isset($this->user->number)){
            return $this->user->number;
        }
        global $storage;
        $user = $storage->getUser($this->user->email);
        $this->user->number = $user->number;
        return $this->user->number;
    }

    /**
     * Returns 'około godziny' or 'o godzinie'.
     */
    public function getDateTimeDivider(){
        if(isset($this->dtFromPicture) && !$this->dtFromPicture){
            return "około godziny";
        }
        return "o godzinie";
    }

    /**
     * Set status (and store statuses history changes)
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public function setStatus($status){
        global $STATUSES;
        if(!array_key_exists($status, $STATUSES)){
            throw new Exception("Odmawiam ustawienia statusu na $status");
        }
        if($status == $this->status){
            logger("Zmiana statusu na ten sam ($status) dla zgłoszenia {$this->id}");
            return;
        }elseif(!in_array($status, $this->getStatus()->allowed)){
            throw new Exception("Odmawiam zmiany statusu z {$this->status} na $status dla zgłoszenia {$this->id}");
        }
        if(!isset($this->statusHistory)){
            $this->statusHistory = [];
        }
        $this->statusHistory[date(DT_FORMAT)] = new JSONObject();
        $this->statusHistory[date(DT_FORMAT)]->old = $this->status;
        $this->statusHistory[date(DT_FORMAT)]->new = $status;

        if ($status == 'confirmed-waiting' || $status == 'confirmed-waitingE') {
            if(!isset($this->sent)) {
                $this->sent = new JSONObject();
            }
            $this->sent->date = date(DT_FORMAT);
            $smData = $this->guessSMData();
            $this->sent->to = $smData->getEmail();
            $this->sent->subject = $this->getEmailSubject();
            $this->sent->method = 'manual';
        }

        $this->status = $status;
    }

    public function getAppPDFFilename(){
        return 'Zgloszenie_' . str_replace('/', '-', $this->number) . '.pdf';
    }

    /**
     * Defines if a plate image should be included in the application.
     * True if plate image is present, and user didn't change plateId
     * value in the application.
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    public function shouldIncludePlateImage(){
        if(!isset($this->carInfo)){
            return false;
        }
        if(!@$this->carInfo->plateId){
            return false;
        }
        if(isset($this->carInfo->plateIdFromImage) 
            && $this->carInfo->plateIdFromImage == $this->carInfo->plateId){
            return true;
        }
        return false;
    }

    public function stopAgresji() {
        if(isset($this->user->stopAgresji)){
            return $this->user->stopAgresji;
        }
        return false;
    }

    /**
     * Zwraca najlepiej pasująca dla adresu zgłoszenia SM/SA.
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public function guessSMData($update = null){
        global $SM_ADDRESSES;
        if(!$update && isset($this->smCity) && !$this->stopAgresji()){
            if($this->smCity !== '_nieznane'){
                return $SM_ADDRESSES[$this->smCity];
            }
        }
        if($this->stopAgresji()){
            return $this->__guessSA();
        }
        return $this->__guessSM();
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     * @SuppressWarnings(PHPMD.CamelCaseMethodName)
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    private function __guessSM(){ // straż miejska
        global $SM_ADDRESSES;
        $city = trimstr2lower($this->address->city);
        if($city == 'krosno' && trimstr2lower(@$this->address->voivodeship) == 'wielkopolskie'){
            $city = 'krosno-wlkp'; // tak, są dwa miasta o nazwie 'Krosno'...
        }
        if(array_key_exists($city, $SM_ADDRESSES)){
            $this->smCity = $city;
            if($city == 'warszawa' && isset($this->address->district)){
                if(array_key_exists($this->address->district, ODDZIALY_TERENOWE)){
                    $this->smCity = ODDZIALY_TERENOWE[$this->address->district];
                }
            }
            return $SM_ADDRESSES[$this->smCity];
        }
        return $SM_ADDRESSES['_nieznane'];
    }

    /** 
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     * @SuppressWarnings(PHPMD.CamelCaseMethodName)
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    private function __guessSA(){ // stop agresji
        global $STOP_AGRESJI;

        $voivodeship = trimstr2lower(@$this->address->voivodeship);
        $city = trimstr2lower($this->address->city);

        if(array_key_exists($voivodeship, $STOP_AGRESJI)){
            if($city == 'szczecin'){
                $voivodeship = policeStationsSzczecin($this);
            }
            $this->smCity = $voivodeship;
            return $STOP_AGRESJI[$voivodeship];
        }
        return $STOP_AGRESJI['default'];
    }

    public function hasAPI(){
        return $this->guessSMData()->hasAPI();
    }

    public function automatedSM(){
        return (boolean)$this->guessSMData()->automated();
    }

    public function unknownSM(){
        return $this->guessSMData()->unknown();
    }

    /**
     * Returns application city in a filename-friendly format.
     */
    public function getSanitizedCity(){
        return mb_ereg_replace("([^\w\d])", '-', $this->guessSMData()->city);
    }

    public function guessUserSex(){
        if(!isset($this->user->sex)){
            $this->user->sex = guess_sex_by_name($this->user->name);
        }
        return SEXSTRINGS[$this->user->sex];
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public function getCategory(){
        global $CATEGORIES;
        return $CATEGORIES[$this->category];
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public function getStatus(){
        global $STATUSES;
        return $STATUSES[$this->status];
    }

    public function isCurrentUserOwner(){
        if(!isLoggedIn()) return false;
        return getCurrentUserEmail() == $this->user->email;
    }

    public function getRecydywa(){
        global $storage;
        if(isset($this->carInfo) && isset($this->carInfo->plateId)){
            return $storage->getRecydywa($this->carInfo->plateId);
        }
        return 0;
    }

    public function getLatexSafeComment(){
        // Remove HTML entities
        $string = preg_replace('/&[a-zA-Z]+;/iu', '', $this->userComment);

        // Remaining special characters (cannot be placed with the others,
        // as then the html entity replace would fail).
        $string = str_replace("\\", " ", $string);
        $string = str_replace("#", "\\#", $string);
        $string = str_replace("$", "\\$", $string);
        $string = str_replace("&", "\\&", $string);
        $string = str_replace("%", "\\%", $string);
        $string = str_replace("{", "\\{", $string);
        $string = str_replace("}", "\\}", $string);
        $string = str_replace("_", "\\_", $string);
        $string = str_replace('"', "''", $string);
        $string = str_replace("^", "\\^{}", $string);
        $string = str_replace("°", "\$^{\\circ}\$", $string);
        $string = str_replace(">", "\\textgreater ", $string);
        $string = str_replace("<", "\\textless ", $string);
        $string = str_replace("~", "\\textasciitilde ", $string);

        return $string;
    }

    public function getJSONSafeComment(){
        // Remove HTML entities
        $string = preg_replace('/&[a-zA-Z]+;/iu', '', $this->userComment);
        $string = str_replace("\\", " ", $string);
        $string = str_replace("'", " ", $string);
        return $string;
    }

    /**
     * Zwraca adres do pliku z mapą lokalizacji zgłoszenia. W razie potrzeby
     * najpierw pobiera ten obrazek z API Google.
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function getMapImage(){
        if(!isset($this->address) || !isset($this->address->latlng)){
            return null;
        }
        $iconEncodedUrl = urlencode('%HTTPS%://%HOST%/img/map-circle.png');
        $mapsUrl = "https://maps.googleapis.com/maps/api/staticmap?center={$this->address->latlng}&zoom=17&size=380x200&maptype=roadmap&markers=anchor:center%7Cicon:$iconEncodedUrl%7C{$this->address->latlng}&key=AIzaSyC2vVIN-noxOw_7mPMvkb-AWwOk6qK1OJ8&format=png";
        
        if(!$this->hasNumber()){
            return $mapsUrl;
        }

        if(isset($this->address->mapImage)){
            return $this->address->mapImage;
        }
        // @TODO refactor warning (duże copy-paste z api.html:saveImgAndThumb())
        $baseDir = 'cdn2/' . $this->getUserNumber();
        if(!file_exists('/var/www/%HOST%/' . $baseDir)){
            mkdir('/var/www/%HOST%/' . $baseDir, 0755, true);
        }
        $baseFileName = $baseDir . '/' . $this->id;

        $fileName     = "/var/www/%HOST%/$baseFileName,ma.png";

        $ifp = @fopen($fileName, 'wb');
        if($ifp === false){
            return $mapsUrl;
        }
        
        $image = file_get_contents($mapsUrl);
        if($image === false){
            return $mapsUrl;
        }
        
        if(fputs($ifp, $image) === false){
            return $mapsUrl;
        }
        fclose($ifp);

        global $storage;
        $this->address->mapImage = "$baseFileName,ma.png";
        $storage->saveApplication($this);
        return "$baseFileName,ma.png";
    }

    public function hasNumber() {
        return isset($this->number);
    }

    public function getFirstName(){
        return preg_split('/\s/', $this->user->name)[0];
    }

    public function getLastName(){
        return preg_split('/^[^\s]+\s/', $this->user->name)[1];
    }

    public function getLon(){
        return explode(',', $this->address->latlng)[1];
    }

    public function getLat(){
        return explode(',', $this->address->latlng)[0];
    }

    public function getTitle(){
        return "[{$this->number}] " . (($this->category == 0)? substr($this->userComment, 0, 150):
            $this->getCategory()->getTitle() )
            . " ({$this->address->address})";
    }

    public function getEmailSubject(){
        return "[{$this->number}] " . (($this->category == 0)? "": $this->getCategory()->getTitle() )
            . " ({$this->address->address})";

    }

    /**
     * Adds a comment to the application.
     * $source <string>
     *  Name of the author | API Miasta | Admin
     */
    public function addComment($source, $comment){
        if(!isset($this->comments)){
            $this->comments = [];
        }
        $date = date(DT_FORMAT);
        $this->comments[$date] = new JSONObject();
        $this->comments[$date]->source = $source;
        $this->comments[$date]->comment = $comment;
    }

    /**
     * Returns info whether the app was allowed to be added
     * to gallery.
     * 
     * returns: boolean
     */
    public function addedToGallery(){
        return ((bool) $this->statements) && ((bool)$this->statements->gallery);
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public function isEditable(){
        global $STATUSES;
        return $STATUSES[$this->status]->editable;
    }

    /**
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    public function hideNameInPdf() {
        return (bool)@$this->statements->hideNameInPdf;
    }

    /**
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    public function getRevision() {
        return @count($this->statusHistory);
    }

    public function isAppOwner() {
        return isLoggedIn() && (getCurrentUserEmail() == $this->user->email);
    }
}

?>
