<?PHP

require_once(__DIR__ . '/Utils.php');
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
            return;
        }
        global $storage;
        $this->id = guidv4();
        $this->added = date(DT_FORMAT);
        $this->user = $storage->getCurrentUser()->data;
        $this->status = 'draft';
        
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

}

?>