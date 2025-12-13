<?PHP namespace app;

require_once(__DIR__ . '/JSONObject.php');
require_once(__DIR__ . '/../integrations/CityAPI.php');
require_once(__DIR__ . '/../Crypto.php');
use \Datetime as Datetime;
use \Exception as Exception;
use \JSONObject as JSONObject;
use recydywa\Recydywa;
use user\User;

/**
 * Application class.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class Application extends JSONObject implements \JsonSerializable {
    public string $externalId = '';
    public string $privateComment  = '';

    private function __construct() {
    }

    /**
     * Creates new Application of initiates it from JSON.
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public static function withJson($json, string $email): Application {
        $instance = new self();
        $instance->__fromJson($json);
        $instance->email = $email;
        $instance->decode();
        @$instance->statusHistory = (array)$instance->statusHistory;
        @$instance->comments = (array)$instance->comments;
        @$instance->extensions = (array)$instance->extensions;
        if(!isset($instance->seq) && $instance->hasNumber()) {
            $instance->seq = extractAppNumer($instance->getNumber());
        }
        if (!isset($instance->user->sex) && !$instance->isEncrypted()) {
            $instance->user->sex = User::_guessSex($instance->user->name);
        }
        return $instance;
    }

    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    function encode(): string {
        if (!isset($_SESSION['user_id']))
            return json_encode($this);

        $clone = Application::withJson(json_encode($this), $this->email);
        // @TODO can this be replaced with:
        // $clone = clone $this;
        // ?
        $clone->encrypted = \crypto\passphrase($_SESSION['user_id'] . $clone->id . $clone->added);

        $encode = fn($value) => \crypto\encode($value, $_SESSION['user_id'], $clone->id . $clone->added);
        $clone->user = $encode(json_encode($clone->user));
        $clone->privateComment = $encode($clone->privateComment);
        if (isset($clone->sent))
            $clone->sent = $encode(json_encode($clone->sent));
        if (isset($clone->address))
            $clone->address = $encode(json_encode($clone->address));
        if (isset($clone->userComment))
            $clone->userComment = $encode($clone->userComment);
        return json_encode($clone);
    }

    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    function decode(): void {
        // not encrypted
        if (!($this->encrypted ?? false))
            return;

        // public access, no session
        if (!($_SESSION['user_id'] ?? false)) {
            return;
        }

        // other user opened
        $encrypted = \crypto\passphrase($_SESSION['user_id'] . $this->id . $this->added);
        if ($encrypted != $this->encrypted) {
            logger("Can't decode application ({$this->id}) with wrong passphrase");
            return;
        }

        $decode = fn($value) => \crypto\decode($value, $_SESSION['user_id'], $this->id . $this->added);
        $this->user = new JSONObject($decode($this->user));
        $this->privateComment = $decode($this->privateComment);
        if (isset($this->sent) && is_string($this->sent))
            $this->sent = new JSONObject($decode($this->sent));
        if (isset($this->address))
            $this->address = new JSONObject($decode($this->address));
        if (isset($this->userComment))
            $this->userComment = $decode($this->userComment);
        
        unset($this->encrypted);
    }

    public function isEncrypted(): bool {
        return $this->encrypted ?? false;
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    private static function genSafeId(User $user): string {
        $id = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
        if (\app\checkId($id))
            throw new Exception("Identyfikator zgłoszenia '$id' dla '{$user->getEmail()}' już istnieje!");
        return $id;
    }

    /**
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public static function withUser(User $user): Application {
        $instance = new self();
        $instance->date = null;
        $instance->id = Application::genSafeId($user);
        $instance->added = date(DT_FORMAT);
        $instance->updateUserData($user);

        $instance->status = 'draft';
        $instance->category = 0;
        $instance->initStatements();
        $instance->address = new JSONObject();
        /*
        2.5.4 (2025-01-15)
          - "Jestem świadoma odpowiedzialności karnej za złożenie fałszywego oświadczenia, wynikającej z art. 233 kk."
        2.5.3 (2025-01-11)
          - post-migration apps
        2.5.2 (2025-01-11)
          - full migration of historical apps
        2.5.1 (2025-01-09)
          - added root $email field
        2.5.0 (2024-12-28)
          - removed absolute myAppsSize, autoSend, exposeData
        2.4.0 (2024-12-09)
          - encryption support
        2.3.0
        2.2.1 (2023-01-12)
          - separate lat & lng
        2.2.0 (2024-01-11):
          - browser property reduced to minimum
          - extended address property
        2.1.0
        */
        $instance->version = '2.5.4';
        $instance->browser = $_SERVER['HTTP_USER_AGENT'];
        return $instance;
    }

    public function updateUserData(User $user): void {
        $this->user = clone $user->data;
        $this->user->number = $user->getNumber();
        $this->stopAgresji = $user->stopAgresji();
        $this->email = $user->getEmail();
        unset($this->user->stopAgresji);
    }

    public function wasSent(): bool {
        return isset($this->sent) && !in_array($this->status, ['draft', 'ready', 'confirmed']);
    }

    public function setLatLng(string|null $latLng): void {
        if ($latLng && preg_match("/\d+.\d+,\d+.\d+/", $latLng)) {
            [$lat, $lng] = explode(',', $latLng);
            $this->address->lat = (float)$lat;
            $this->address->lng = (float)$lng;
            unset($this->address->latlng);
            return;
        }
        $this->address->lat = null;
        $this->address->lng = null;
    }

    public function initStatements() {
        if(isset($this->statements)){
            return;
        }
        $this->statements = new JSONObject();
        $this->statements->witness = false;
        $this->statements->gallery = false;
    }

    /**
     * Returns application date in Y-m-d format.
     */
    public function getDate($pattern='d.MM.y'){
        return formatDateTime($this->date, $pattern);
    }

    public function getSentDate($pattern="d.MM.y") {
        if (isset($this->sent->date))
            return formatDateTime($this->sent->date, $pattern);
        return $this->getDate($pattern);
    }

    /**
     * Returns application time in H:i format.
     */
    public function getTime(): string{
        $format = 'H:i'; // 24-hour format of an hour with leading zeros : Minutes with leading zeros
        if (($this->inexactHour ?? false) && !($this->dtFromPicture ?? true)) {
            $format = 'G:00'; // 24-hour format of an hour without leading zeros
        }
        return (new DateTime($this->date))->format($format);
    }

    /**
     * Returns application number (UD/X/Y)
     */
    public function getNumber(){
        return $this->number ?? null;
    }

    /**
     * Returns (lazy initialized) User number.
     */
    public function getUserNumber(){
        if(isset($this->user->number)){
            return $this->user->number;
        }
        $user = \user\get($this->email);
        $this->user->number = $user->number;
        return $this->user->number;
    }

    /**
     * Returns 'około godziny' or 'o godzinie'.
     */
    public function getDateTimeDivider(): string{
        if (($this->inexactHour ?? false) && !($this->dtFromPicture ?? true))
            return "około godziny";
        return "o godzinie";
    }

    /**
     * Set status (and store statuses history changes)
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public function setStatus(string $status, bool $force=false): void{
        global $STATUSES;

        if(!array_key_exists($status, $STATUSES)){
            throw new Exception("Odmawiam ustawienia statusu na '$status'");
        }
        if($status == $this->status){
            logger("Zmiana statusu na ten sam ($status) dla zgłoszenia {$this->id}");
            return;
        }elseif(!in_array($status, $this->getStatus()->allowed)){
            if (!$force)
                throw new Exception("Odmawiam zmiany statusu z '{$this->status}' na '$status' dla zgłoszenia '{$this->id}'");
        }
        if(!isset($this->statusHistory))
            $this->statusHistory = [];
        
        $now = (new DateTime())->format(DT_FORMAT_LONG);
        $this->statusHistory[$now] = new JSONObject();
        $this->statusHistory[$now]->old = $this->status;
        $this->statusHistory[$now]->new = $status;

        $this->status = $status;

        if (isset($this->carInfo->plateId))
            \recydywa\delete($this->carInfo->plateId);
    }

    public function getAppFilename(string $suffix, string $prefix='Zgloszenie_'): string {
        return $prefix . str_replace('/', '-', $this->number) . "$suffix";
    }

    /**
     * Defines if a plate image should be included in the application.
     * True if plate image is present, and user didn't change plateId
     * value in the application.
     */
    public function shouldIncludePlateImage(): bool {
        return ($this->carInfo->plateIdFromImage ?? false )
            == ($this->carInfo->plateId ?? true);
    }

    public function stopAgresji() {
        return $this->stopAgresji ?? false;
    }

    /**
     * Zwraca najlepiej pasująca dla adresu zgłoszenia SM/SA.
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(CamelCaseVariableName)
     */
    public function guessSMData(bool $update=false): \SM {
        global $SM_ADDRESSES;
        global $STOP_AGRESJI;

        $smCity = $this->smCity ?? null;
        
        // don't update, smCity is set
        if (!$update && $smCity) {
            if ($this->stopAgresji())
                return $STOP_AGRESJI[$this->smCity];
            return $SM_ADDRESSES[$this->smCity];
        }

        // smCity is set, should update but it is not possible as the app is encrypted
        if ($this->isEncrypted() && $smCity) {
            if ($this->stopAgresji())
                return $STOP_AGRESJI[$this->smCity];
            return $SM_ADDRESSES[$this->smCity];
        }

        // can't update
        if (!$this->address) {
            if ($this->stopAgresji())
                return $STOP_AGRESJI[$smCity ?? 'default'];
            return $SM_ADDRESSES[$smCity ?? '_nieznane'];
        }

        // can update
        if ($this->stopAgresji()) {
            $this->smCity = \StopAgresji::guess($this->address);
            return $STOP_AGRESJI[$this->smCity];
        }
        $this->smCity = \SM::guess($this->address);
        return $SM_ADDRESSES[$this->smCity];
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
        return SEXSTRINGS[$this->user->sex ?? '?'];
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
        $status = $STATUSES[$this->status];

        if ($this->isEncrypted())
            return $status;
        if (!$this->guessSMData()->isPolice())
            return $status;
        if (str_ends_with($status->action, 'w SM')) {
            $status->comment = str_replace('w SM', 'na Policji', $status->comment);
            $status->action = str_replace('w SM', 'na Policji', $status->action);
        }
        return $status;
    }

    public function isCurrentUserOwner(){
        try {
            return \user\currentEmail() == $this->email;
        } catch(Exception $e) {
            return false;
        }
    }

    public function getRecydywa(): Recydywa {
        if(isset($this->carInfo->plateId) && $this->status != 'archived'){
            return \recydywa\get($this->carInfo->plateId);
        }
        return new Recydywa(); // fake it
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
        $comment = htmlspecialchars($this->userComment);
        $comment = preg_replace('/(https?:\/\/[a-zA-Z0-9.-]+\.[a-zA-Z]{2,3}(?:\/\S*(?<!\.))?)/ims',
            '<a href="$1" target="_blank">$1</a> ', $comment);
        return str_replace("Http", "http", $comment);
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
        $iconEncodedUrl = urlencode('https://uprzejmiedonosze.net/img/map-circle.png');
        $lngLat = $this->getLngLat();
        $mapsUrl = "https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/static/url-$iconEncodedUrl($lngLat)/$lngLat,16,0/380x200?access_token=pk.eyJ1IjoidXByemVqbWllZG9ub3N6ZXQiLCJhIjoiY2xxc2VkbWU3NGthZzJrcnExOWxocGx3bSJ9.r1y7A6C--2S2psvKDJcpZw&_=1";

        if($this->hasNumber() && isset($this->address->mapImage)){
            return $this->address->mapImage;
        }

        $baseDir = 'cdn2/' . $this->getUserNumber();
        if(!file_exists(ROOT . $baseDir)){
            mkdir(ROOT . $baseDir, 0755, true);
        }
        $baseFileName = $baseDir . '/' . $this->id;

        $fileName     = ROOT . "$baseFileName,ma.png";

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

        $this->address->mapImage = "$baseFileName,ma.png";
        \app\save($this);
        return "$baseFileName,ma.png";
    }

    public function isMapImageInCDN() {
        return $this->hasNumber() && isset($this->address->mapImage);
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
            $this->getCategory()->getInformal() )
            . " ({$this->address->address})";
    }

    public function getEmailSubject(){
        $title = preg_replace('/\s\(.*\)/', '', $this->getCategory()->getInformal());
        return "[{$this->number}] " . (($this->category == 0)? "Wykroczenie": $title)
            . " ({$this->address->address})";
    }

    public function getShortAddress(): string {
        $shortStreetAddress = $this->getAddress();
        if (str_ends_with($this->user->address, $this->address->city ?? 'none')) {
            $re = '/,\s' . ($this->address->city ?? 'none') . '$/';
            return preg_replace($re, '', $shortStreetAddress);
        }
        return $shortStreetAddress;
    }

    public function getAddress(): string {
        return preg_replace('/\D.*\s(\w{3,}\s(\w{1,3}\s)?\w{3,}\s(\w{1,3}\s)?[\/\w\d]+,\s.+)/iu', '$1', $this->address->address);
    }

    public function addComment(string $source, string $comment, ?string $status=null){
        if(!isset($this->comments))
            $this->comments = [];

        $date = (new DateTime())->format(DT_FORMAT_LONG);
        $this->comments[$date] = new JSONObject();
        $this->comments[$date]->source = $source;
        $this->comments[$date]->comment = $comment;
        $this->comments[$date]->status = $status;
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     */
    public function isEditable(): bool {
        global $STATUSES;
        return $STATUSES[$this->status]->editable;
    }

    public function getRevision() {
        return count($this->statusHistory ?? []);
    }

    public function isAppOwner(User|null $user): bool {
        if (!$user) return false;
        return $user->getEmail() == $this->email;
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

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getPrivateComment(): string
    {
        return $this->privateComment;
    }

    public function jsonSerialize(): array
    {
        $data = (array)$this;
        $hasDefaults = ['externalId', 'privateComment'];

        foreach ($hasDefaults as $key) {
            if ($data[$key] === '') {
                unset($data[$key]);
            }
        }

        return $data;
    }

    public function getSafeImageUrl(): string {
        $imageFileName = $this->contextImage->thumb ?? 'img/fff-1.png';

        $pixelate = !($this->showImage ?? false);
        if ($pixelate)
            $imageFileName .= '?pixelate';
        $image = \crypto\encode($imageFileName, CRYPTO_KEY, CRYPTO_IV);
        return "img-$image.php?sessionless";
    }

    public function canImageBeShown(User|null $whoIsWathing, bool|null $canShareRecydywa=null): bool {
        // display image if app owner allowed sharing 
        $showImage = $canShareRecydywa ?? \user\canShareRecydywa($this->email);
        
        // or added it to gallery
        $showImage = $showImage || $this->statements->gallery;
        
        // hide photos with faces
        $showImage = $showImage && ($app->faces->count ?? 0) == 0;

        // app owner can always see his photos
        $showImage = $showImage || $this->isAppOwner($whoIsWathing);

        return $showImage;
    }

    public function areAllImagesVertical(): bool {
        $isVertical = fn($image) => ($image->height ?? false) > ($image->width ?? false);

        return $isVertical($this->contextImage ?? null)
            && $isVertical($this->carImage ?? null)
            && $isVertical($this->thirdImage ?? null);
    }

    public function sentViaAPI(): bool {
        return ($this->sent->method ?? false) == 'Poznan';
    }
}
