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
        $this->date = date(DT_FORMAT);
        $this->id = guidv4();
        $this->added = date(DT_FORMAT);
        $this->user = $storage->getCurrentUser()->data;
        $this->status = 'draft';
        $this->category = 7;
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

    /**
     * Defines if a plate image should be included in the application.
     * True if plate image is present, and user didn't change plateId
     * value in the application.
     */
    public function shouldIncludePlateImage(){
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
            return SM_ADDRESSES[$this->smCity];
        }

        $city = trim(strtolower($this->address->city));

        if(array_key_exists($city, SM_ADDRESSES)){
            $this->smCity = $city;
            return SM_ADDRESSES[$this->smCity];
        }

        $address = trim(strtolower($this->address->address));
        foreach(SM_ADDRESSES as $c => $a){
            if (strpos($address, $c) !== false){
                $this->smCity = $c;
                return SM_ADDRESSES[$this->smCity];
            }
        }
        return ['(skontaktuj się z autorem \\\\ aby podać adres SM dla twojego miasta)', null];
    }

    public function guessUserSex(){
        return guess_sex_by_name($this->user->name);
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

}

?>