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
    private function __construct() {

    }

    public static function withJson($json): Application {
        $instance = new self();
        $instance->__fromJson($json);
        @$instance->statusHistory = (array)$instance->statusHistory;
        @$instance->comments = (array)$instance->comments;
        @$instance->extensions = (array)$instance->extensions;
        if(!isset($instance->seq) && $instance->hasNumber()) {
            $instance->seq = extractAppNumer($instance->getNumber());
        }
        $instance->migrateLatLng();
        $instance->migrateSent();
        if (!isset($instance->user->sex)) {
            $instance->user->sex = User::_guessSex($instance->user->name);
        }
        return $instance;
    }

    public static function withUser(User $user): Application {
        $instance = new self();
        $instance->date = null;
        $instance->id = genSafeId();
        $instance->added = date(DT_FORMAT);
        $instance->updateUserData($user);

        $instance->status = 'draft';
        $instance->category = 0;
        $instance->initStatements();
        $instance->address = new JSONObject();
        $instance->version = '2.2.1';

        /*
        2.2.1 (2023-01-12)
          - separate lat & lng
        2.2.0 (2024-01-11):
          - browser property reduced to minimum
          - extended address property
        2.1.0
        */
        $instance->browser = $_SERVER['HTTP_USER_AGENT'];
        return $instance;
    }

    public function updateUserData(User $user): void {
        $this->user = $user->data;
        $this->user->number = $user->getNumber();
        $this->stopAgresji = $user->stopAgresji();
        unset($this->user->stopAgresji);
        unset($this->user->myAppsSize);
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

    public function setLatLng(string|null $latLng): void {
        if ($latLng && preg_match("/\d+.\d+,\d+.\d+/", $latLng)) {
            [$lat, $lng] = explode(',', $latLng);
            $this->address->lat = (float)$lat;
            $this->address->lng = (float)$lng;
            return;
        }
        $this->address->lat = null;
        $this->address->lng = null;
    }

    private function migrateLatLng(): void {
        if (!empty($this->address->latlng))
            $this->setLatLng($this->address->latlng);
    }

    public function initStatements() {
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
    public function getTime(): string{
        $format = 'H:i'; // 24-hour format of an hour with leading zeros : Minutes with leading zeros
        if ($this->version < '2.1.0' && isset($this->dtFromPicture) && !$this->dtFromPicture) {
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
    public function getDateTimeDivider(): string{
        if ($this->version < '2.1.0' && isset($this->dtFromPicture) && !$this->dtFromPicture)
            return "około godziny";
        return "o godzinie";
    }

    /**
     * Set status (and store statuses history changes)
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public function setStatus(string $status): void{
        global $STATUSES;
        $now = date(DT_FORMAT);

        if(!array_key_exists($status, $STATUSES)){
            throw new Exception("Odmawiam ustawienia statusu na '$status'");
        }
        if($status == $this->status){
            logger("Zmiana statusu na ten sam ($status) dla zgłoszenia {$this->id}");
            return;
        }elseif(!in_array($status, $this->getStatus()->allowed)){
            throw new Exception("Odmawiam zmiany statusu z '{$this->status}' na '$status' dla zgłoszenia '{$this->id}'");
        }
        if(!isset($this->statusHistory)){
            $this->statusHistory = [];
        }
        $this->statusHistory[$now] = new JSONObject();
        $this->statusHistory[$now]->old = $this->status;
        $this->statusHistory[$now]->new = $status;

        if ($status == 'confirmed-waiting' || $status == 'confirmed-waitingE') {
            if(!isset($this->sent)) {
                $this->sent = new JSONObject();
            }
            $this->sent->date = $now;
            $smData = $this->guessSMData();
            $this->sent->to = $smData->getEmail();
            $this->sent->subject = $this->getEmailSubject();
            $this->sent->method = 'manual';
        }

        $this->status = $status;
    }

    public function getAppPDFFilename(): string {
        return 'Zgloszenie_' . str_replace('/', '-', $this->number) . '.pdf';
    }

    /**
     * Defines if a plate image should be included in the application.
     * True if plate image is present, and user didn't change plateId
     * value in the application.
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    public function shouldIncludePlateImage(): bool {
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
        if(isset($this->stopAgresji)){
            return $this->stopAgresji;
        }
        return false;
    }

    /**
     * Zwraca najlepiej pasująca dla adresu zgłoszenia SM/SA.
     */
    public function guessSMData(bool $update=false): object {
        global $SM_ADDRESSES;
        global $STOP_AGRESJI;
        if(!$update && isset($this->smCity) && !$this->stopAgresji()){
            if($this->smCity !== '_nieznane'){
                return $SM_ADDRESSES[$this->smCity];
            }
        }
        if(!isset($this->address)) {
            return $SM_ADDRESSES['_nieznane'];
        }
        if($this->stopAgresji()){
            $this->smCity = Application::__guessSA($this->address);
            return $STOP_AGRESJI[$this->smCity];
        }
        $this->smCity = Application::__guessSM($this->address);
        return $SM_ADDRESSES[$this->smCity];
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     * @SuppressWarnings(PHPMD.CamelCaseMethodName)
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    public static function __guessSM(object $address): string{ // straż miejska
        global $SM_ADDRESSES;
        $city = trimstr2lower($address->city);
        if($city == 'krosno' && trimstr2lower(@$address->voivodeship) == 'wielkopolskie'){
            $city = 'krosno-wlkp'; // tak, są dwa miasta o nazwie 'Krosno'...
        }
        if(array_key_exists($city, $SM_ADDRESSES)){
            $smCity = $city;
            if($city == 'warszawa' && isset($address->district)){
                if(array_key_exists($address->district, ODDZIALY_TERENOWE)){
                    $smCity = ODDZIALY_TERENOWE[$address->district];
                }
            }
            return $smCity;
        }
        $smCity = '_nieznane';
        return $smCity;
    }

    /** 
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     * @SuppressWarnings(PHPMD.CamelCaseMethodName)
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    public static function __guessSA(object $address): string { // stop agresji
        global $STOP_AGRESJI;

        $voivodeship = trimstr2lower(@$address->voivodeship);
        $city = trimstr2lower($address->city);

        if(array_key_exists($voivodeship, $STOP_AGRESJI)){
            if($city == 'szczecin'){
                $voivodeship = policeStationsSzczecin($address);
            }
            return $voivodeship;
        }
        return 'default';
    }

    public function hasAPI(): bool{
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
    public function getSanitizedCity(): string{
        return mb_ereg_replace("([^\w\d])", '-', $this->guessSMData()->city);
    }

    public function guessUserSex(){
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

    private static function getLatexSafe($input){
        // Remove HTML entities
        $string = preg_replace('/&[a-zA-Z]+;/iu', '', $input);

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

    public function getLatexSafeComment(){
        return $this->getLatexSafe($this->userComment);
    }

    public function getLatexSafeAddress() {
        return $this->getLatexSafe($this->address->address);
    }

    public function getJSONSafeComment(){
        // Remove HTML entities
        $string = preg_replace('/&[a-zA-Z]+;/iu', '', $this->userComment);
        $string = str_replace("\\", " ", $string);
        $string = str_replace("'", " ", $string);
        return $string;
    }

    public function getAHrefedComment(){
        $string = preg_replace('/(https?:\/\/[a-zA-Z0-9.-]+\.[a-zA-Z]{2,3}(?:\/\S*(?<!\.))?)/ims',
            '<a href="$1" target="_blank">$1</a> ', $this->userComment);
        return str_replace("Http", "http", $string);
    }


    /**
     * Zwraca adres do pliku z mapą lokalizacji zgłoszenia. W razie potrzeby
     * najpierw pobiera ten obrazek z API Google.
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function getMapImage(){
        if(!isset($this->address) || !isset($this->address->lat)){
            return null;
        }
        $iconEncodedUrl = urlencode('%HTTPS%://%HOST%/img/map-circle.png');
        $lngLat = $this->getLngLat();
        $mapsUrl = "https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/static/url-$iconEncodedUrl($lngLat)/$lngLat,16,0/380x200?access_token=pk.eyJ1IjoidXByemVqbWllZG9ub3N6ZXQiLCJhIjoiY2xxc2VkbWU3NGthZzJrcnExOWxocGx3bSJ9.r1y7A6C--2S2psvKDJcpZw&_=1";

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
        
        $image = @file_get_contents($mapsUrl);
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

    private function getLngLat(): string|null {
        if (isset($this->address->lng))
            return sprintf('%.4F,%.4F', $this->address->lng, $this->address->lat);
        return null;
    }

    public function getLatLng(): string|null {
        if (isset($this->address->lng))
            return sprintf('%.4F,%.4F', $this->address->lat, $this->address->lng);
        return null;
    }

    public function getMapUrl(): string {
        return "https://www.google.com/maps/search/?api=1&query={$this->getLatLng()}";
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
    public function isEditable(): bool {
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

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public function getExtensionsText() {
        global $EXTENSIONS;
        if(!isset($this->extensions) || count($this->extensions) == 0) {
            return '';
        }
        if ($this->category == 0)
            $text = 'Pojazd';
        else 
            $text = 'Dodatkowo pojazd';
        
        $extCount = count($this->extensions);
        foreach($this->extensions as $extension){
            $text .= ' ';
            $text .= $EXTENSIONS[$extension]->title;
            if (--$extCount > 0) {
                $text .= ' oraz';
            }
        }
        return "$text.";
    }
}

?>
