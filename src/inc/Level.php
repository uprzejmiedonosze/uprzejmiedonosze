<?PHP

require_once(__DIR__ . '/JSONObject.php');

class Level extends JSONObject {
  /**
   * Initites new Level from JSON.
   */
  public function __construct($json = null) {
    parent::__construct($json);
  }

  public function getImg() : string {
    return $this->img;
  }

  public function getDesc() : string {
    return $this->desc;
  }

  public function getPoints() : int {
    return $this->points;
  }

  public function getLetter() : int {
    return $this->letter;
  }
}
