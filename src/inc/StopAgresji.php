<?PHP

require_once(__DIR__ . '/SM.php');
require_once(__DIR__ . '/PoliceStationAreas.php');

class StopAgresji extends SM {
  public function unknown() {
    return false;
  }

  public function getCity() {
    return "Stop Agresji Drogowej " . $this->voivodeship;
  }

  /** 
   * @SuppressWarnings(PHPMD.CamelCaseVariableName)
   * @SuppressWarnings(PHPMD.CamelCaseMethodName)
   * @SuppressWarnings(PHPMD.ErrorControlOperator)
   */
  public static function guess(object $address): string  { // stop agresji
    global $STOP_AGRESJI;

    $voivodeship = trimstr2lower(@$address->voivodeship);
    $city = trimstr2lower($address->city);

    if (array_key_exists($voivodeship, $STOP_AGRESJI)) {
      if ($city == 'szczecin') {
        $policeStationAreas = new \PoliceStationAreas;
        return $policeStationAreas->guess($address->lat, $address->lng) ?? 'szczecin-miasto';
      }
      return $voivodeship;
    }
    return 'default';
  }
}

?>
