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
            return;
        }
        global $storage;
        $user = $storage->getCurrentUser();

        $this->date = null;
        $this->id = guidv4();
        $this->added = date(DT_FORMAT);
        $this->user = $user->data;
        $this->user->number = $user->getNumber();
        $this->user->sex = guess_sex_by_name($user->data->name);
        $this->status = 'draft';
        $this->category = 7;
        $this->initStatements();
    }

    public function initStatements(){
        if(isset($this->statements)){
            return;
        }
        $this->statements = new JSONObject();
        $this->statements->witness = false;
    }

    /**
     * Returns application date in Y-m-d format.
     */
    public function getDate(){
        return (new DateTime($this->date))->format('Y-m-d');
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
        if(isset($this->smCity)){
            if($this->smCity !== '_nieznane'){
                return SM_ADDRESSES[$this->smCity];
            }
        }

        $city = trim(mb_strtolower($this->address->city, 'UTF-8'));

        if(array_key_exists($city, SM_ADDRESSES)){
            $this->smCity = $city;
            return SM_ADDRESSES[$this->smCity];
        }

        $address = trim(mb_strtolower($this->address->address, 'UTF-8'));
        foreach(SM_ADDRESSES as $c => $a){
            if (strpos($address, $c) !== false){
                $this->smCity = $c;
                return SM_ADDRESSES[$this->smCity];
            }
        }
        return SM_ADDRESSES['_nieznane'];
    }

    /**
     * Returns application city in a filename-friendly format.
     */
    public function getSanitizedCity(){
        return mb_ereg_replace("([^\w\d])", '-', $this->guessSMData()[2]);
    }

    public function guessUserSex(){
        if(!isset($this->user->sex)){
            $this->user->sex = guess_sex_by_name($this->user->name);
        }
        return SEXSTRINGS[$this->user->sex];
    }

    public function getCategory(){
        return CATEGORIES[$this->category];
    }

    public function getStatus(){
        return STATUSES[$this->status];
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

}

?>