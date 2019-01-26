<?PHP

require_once(__DIR__ . '/Utils.php');
require_once(__DIR__ . '/JSONObject.php');

class Application extends JSONObject{
    public function __construct($json = null) {
        logger("Application::__construct");
        if($json){
            logger("Application::__construct with json");
            parent::__construct($json);
        }else{
            logger("Application::__construct no json");
            global $storage;

            $this->id = guidv4();
            $this->added = date(DT_FORMAT);
            $this->user = $storage->getCurrentUser()->data;
            $this->status = 'draft';
        }
    }

    public function getDate(){
        return (new DateTime($this->date))->format('Y-m-d');
    }

    public function getTime(){
        return (new DateTime($this->date))->format('H:i');
    }

}

?>