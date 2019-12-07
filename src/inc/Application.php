<?PHP

require_once(__DIR__ . '/utils.php');
require_once(__DIR__ . '/JSONObject.php');

/**
 * Application class.
 */
class Application extends JSONObject{
    /**
     * Creates new Application of initites it from JSON.
     */
    public function __construct($json = null) {
        if($json){
            parent::__construct($json);
            @$this->statusHistory = (array)$this->statusHistory;
            @$this->comments = (array)$this->comments;
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
        $this->category = 7;
        $this->initStatements();
        $this->version = '1.0.1';
    }

    public function initStatements(){
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
    public function getDate(){
        return (new DateTime($this->date))->format('Y-m-d');
    }

    /**
     * Returns date in "January 2017" format.
     */
    public function getMonthYear(){
        $date = new DateTime($this->date);
        $MONTHS = [
            'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec',
            'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'
        ];
        return $MONTHS[intval($date->format('m'))] . ' '. $date->format('Y');
    }

    /**
     * Returns application time in H:i format.
     */
    public function getTime(){
        return (new DateTime($this->date))->format('H:i');
    }

    /**
     * Returns application number (UD/X/Y)
     */
    public function getNumber(){
        return $this->number;
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
     */
    public function setStatus($status){
        if(!isset($this->statusHistory)){
            $this->statusHistory = [];
        }
        $this->statusHistory[date(DT_FORMAT)] = new JSONObject();
        $this->statusHistory[date(DT_FORMAT)]->old = $this->status;
        $this->statusHistory[date(DT_FORMAT)]->new = $status;
        $this->status = $status;
    }

    public function getAppPDFFilename(){
        return 'Zgloszenie_' . str_replace('/', '-', $this->number) . '.pdf';
    }

    public function getSMPDFFilename(){
        return 'Zgloszenie_' . str_replace('/', '-', $this->number) . '-SM.pdf';
    }

    /**
     * Returns prefix for image filenames – used while sending images
     * via API to SM.
     */
    public function getAppImageFilenamePrefix(){
        return str_replace('/', '-', $this->number);
    }

    /**
     * Defines if a plate image should be included in the application.
     * True if plate image is present, and user didn't change plateId
     * value in the application.
     */
    public function shouldIncludePlateImage(){
        if(!isset($this->carInfo)){
            return false;
        }
        if(!$this->carInfo->plateId){
            return false;
        }
        if(isset($this->carInfo->plateIdFromImage) 
            && $this->carInfo->plateIdFromImage == $this->carInfo->plateId){
            return true;
        }
        return false;
    }

    /**
     * Zwraca najlepiej pasująca dla adresu zgłoszenia SM w postaci tablicy:
     * [adres w formacie latex, email]
     */
    public function guessSMData(){
        global $SM_ADDRESSES;
        if(isset($this->smCity)){
            if($this->smCity !== '_nieznane'){
                return $SM_ADDRESSES[$this->smCity];
            }
        }

        $city = trim(mb_strtolower($this->address->city, 'UTF-8'));

        if(array_key_exists($city, $SM_ADDRESSES)){
            $this->smCity = $city;
            return $SM_ADDRESSES[$this->smCity];
        }

        $address = trim(mb_strtolower($this->address->address, 'UTF-8'));
        foreach($SM_ADDRESSES as $c => $a){
            if (strpos($address, $c) !== false){
                $this->smCity = $c;
                return $SM_ADDRESSES[$this->smCity];
            }
        }
        return $SM_ADDRESSES['_nieznane'];
    }

    public function hasAPI(){
        return $this->guessSMData()->hasAPI();
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

    public function getCategory(){
        global $CATEGORIES;
        return $CATEGORIES[$this->category];
    }

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
        return $string;
    }

    /**
     * Zwraca adres do pliku z mapą lokalizacji zgłoszenia. W razie potrzeby
     * najpierw pobiera ten obrazek z API Google.
     */
    public function getMapImage(){
        if(!isset($this->address) || !isset($this->address->latlng)){
            return null;
        }
        $mapsUrl = "https://maps.googleapis.com/maps/api/staticmap?center={$this->address->latlng}&zoom=17&size=380x200&maptype=roadmap&markers=color:red%7Clabel:o%7C{$this->address->latlng}&key=AIzaSyC2vVIN-noxOw_7mPMvkb-AWwOk6qK1OJ8&format=png";
        
        if($this->status == 'draft' || $this->status == 'ready'){
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

    /**
     * Adds a comment to the application.
     * $source <string>
     *  Name of the author | API Miasta | Admin
     */
    public function addComment($source, $comment){
        if(!isset($this->comments)){
            $this->comments = [];
        }
        $this->comments[date(DT_FORMAT)] = new JSONObject();
        $this->comments[date(DT_FORMAT)]->source = $source;
        $this->comments[date(DT_FORMAT)]->comment = $comment;
    }
}

?>