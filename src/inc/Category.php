<?PHP

require_once(__DIR__ . '/JSONObject.php');

/**
 * Status class.
 */
class Category extends JSONObject{
    /**
     * Initites new Status from JSON.
     */
    public function __construct($json = null) {
        parent::__construct($json);
    }

    public function getTitle(){
        return $this->title;
    }

    public function getShort(){
        return $this->short;
    }

    public function getDesc(){
        return $this->descs;
    }

    public function getLaw(){
        return $this->law;
    }

    public function getPrice(){
        return $this->price;
    }
}
