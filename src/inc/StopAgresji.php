<?PHP

require_once(__DIR__ . '/SM.php');

class StopAgresji extends SM {
  public function unknown(){
    return false;
  }

  public function getCity(){
    return "Stop Agresji Drogowej " . $this->voivodeship;
  }
}
