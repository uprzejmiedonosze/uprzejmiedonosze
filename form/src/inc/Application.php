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
     * Export application as a onedimensional array.
     */
    public function flatten(){
        $app = clone $this;
        $app->tex = new stdClass();
    
        $app->tex->appUrl = '{https://%HOST%/ud-' . $app->id . '.html}';
        $app->tex->root = __DIR__ . '/../public/';
        $app->tex->contextImage = '{' . $app->tex->root . $app->contextImage->thumb . '}';
        $app->tex->carImage = '{' . $app->tex->root . $app->carImage->thumb . '}';

        $app->tex->shouldIncludePlateImage = $this->shouldIncludePlateImage();
        $app->tex->plateImage = '{' . $app->tex->root . $app->carInfo->plateImage . '}';
    
        $app->tex->sm = $app->guessSMData()[0];
        $app->tex->sex = $app->guessUserSex();
    
        $app->tex->msisdn = (trim($app->user->msisdn) === "")?"": "Tel: {$app->user->msisdn}";
        $app->tex->category = CATEGORIES[$this->category][1];
    
        return (array)$app;
    }

    /**
     * Zwraca najlepiej pasująca dla adresu zgłoszenia SM w postaci tablicy:
     * [adres w formacie latex, email]
     */
    public function guessSMData(){

    	$city = trim(strtolower($this->address->city));

    	if(array_key_exists($city, SM_ADDRESSES)){
    		return SM_ADDRESSES[$city];
    	}

    	$address = trim(strtolower($this->address->address));
    	foreach(SM_ADDRESSES as $c => $a){
    		if (strpos($address, $c) !== false){
    			return $a;
    		}
    	}
    	return ['(skontaktuj się z autorem \\\\ aby podać adres SM dla twojego miasta)', null];
    }

    public function guessUserSex(){
        return guess_sex_by_name($this->user->name);
    }

    /** 
     * Print application as HTML.
     */
    public function print($printActions = null){
        $commonClasses = 'ui-btn ui-corner-all ui-btn-icon-left ui-btn-inline ui-alt-icon -ui-nodisc-icon';
        
        $app_date = $this->getDate();
        $app_hour = $this->getTime();
        $category = CATEGORIES[$this->category][1];
        $sex      = $this->guessUserSex();
        $bylam    = $sex['bylam'];
        
        $status   = $this->status;
        $statusClass = STATUSES[$status][3];
        $statusIcon  = STATUSES[$status][2];
        $buttons = ($printActions)? $this->getActionButtons(): "";
    
        $plateImage = ($this->shouldIncludePlateImage())? '<img id="plateImage" src="' . $this->carInfo->plateImage . '"/>': '';
    
        echo @<<<HTML
           
        <div id="$this->id" class="application $statusClass status-$status" data-collapsed-icon="$statusIcon" data-expanded-icon="carat-d" data-role="collapsible" data-filtertext="{$this->address->address} $this->number $this->date {$this->carInfo->plateId} {$this->userComment} $category">
             <h3>$this->number ($app_date) {$this->address->address}</h3>
             <p data-role="listview" data-inset="false">
                <p>W dniu <b>$app_date</b> roku o godzinie
                    <b>$app_hour</b> $bylam świadkiem pozostawienia
                    samochodu o nr rejestracyjnym <b>{$this->carInfo->plateId}</b>
                    pod adresem <b>{$this->address->address}</b>.
                    $category</p>
                <p>{$this->userComment}</p>
                <div data-role="controlgroup" data-type="horizontal" data-mini="true">
                    <a href="/ud-{$this->id}.html" class="$commonClasses ui-nodisc-icon ui-icon-eye">szczegóły</a>
                    <a href="{$this->id}.pdf" download="{$this->number}" data-ajax="false" class="$commonClasses ui-nodisc-icon ui-icon-mail">PDF</a>
                </div>
                <div id="pics" class="ui-grid-a ui-responsive">
                    <div class="ui-block-a">
                        <a href="/ud-{$this->id}.html">
                            <img class="lazyload photo-thumbs" data-src="{$this->contextImage->thumb}"> 
                        </a>
                    </div>
                    <div class="ui-block-b">
                        <a href="/ud-{$this->id}.html">
                            <img class="lazyload photo-thumbs" data-src="{$this->carImage->thumb}">
                        </a>
                    </div>
                </div>
                $buttons
            </p>
        </div>
HTML;
    }

    private function getActionButtons(){
        $commonClasses = 'ui-btn ui-corner-all ui-btn-icon-left ui-btn-inline ui-alt-icon -ui-nodisc-icon';
    
        $statusActions = '';
        foreach(STATUSES as $key => $val){
            if(!isset($val[3])){ // class is empty, this is a draft or ready
                continue;
            }
            $txt = $val[1];
            $icon = 'ui-icon-' . $val[2];
            $disabled = ($key == $this->status)?'ui-state-disabled':'';
            $statusActions .= <<<HTML
                <a href="#" onclick="action('$key', '$this->id')" class="$commonClasses $disabled $icon status-$key">$txt</a>
HTML;
        }
    
        return <<<HTML
        <div data-role="controlgroup" data-type="horizontal" data-mini="true" class="actions">
            $statusActions
        </div>
HTML;
    }

}

?>