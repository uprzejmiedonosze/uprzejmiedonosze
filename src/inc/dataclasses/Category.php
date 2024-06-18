<?PHP

require_once(__DIR__ . '/JSONObject.php');

/**
 * Category class.
 */
class Category extends JSONObject{
    /**
     * Initites new Category from JSON.
     */
    public function __construct($json = null) {
        parent::__construct($json);
    }

    public function getTitle(){
        return $this->title;
    }

    public function getShort(){
        return $this->short ?? $this->formal;
    }

    public function getFormal(){
        return $this->formal;
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

    public function getMandate(){
        return $this->mandate;
    }

    public function getPoints(){
        return $this->points;
    }

    public function isStopAgresjiOnly(){
        return $this->stopAgresjiOnly;
    }
}
