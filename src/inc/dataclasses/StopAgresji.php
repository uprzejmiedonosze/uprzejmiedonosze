<?PHP

require_once(__DIR__ . '/SM.php');
require_once(__DIR__ . '/PoliceStationAreas.php');

class StopAgresji extends SM {
  public function unknown(): bool {
    return false;
  }

  public function getCity(): string {
    return "Stop Agresji Drogowej " . $this->voivodeship;
  }

  /**
   * @SuppressWarnings(PHPMD.MissingImport)
   * @SuppressWarnings(PHPMD.CamelCaseVariableName)
   */
  public static function guess(object $address): string  { // stop agresji
    global $STOP_AGRESJI;

    $voivodeship = trimstr2lower(@$address->voivodeship);
    $city = trimstr2lower($address->city);

    // this is a complex alghorithm and runs on a single city at the moment
    // it's better to run it only when needed
    if ($city == 'szczecin') {
      $policeStationAreas = new \PoliceStationAreas;
      return $policeStationAreas->guess($address->lat, $address->lng) ?? 'szczecin-miasto';
    }

    if (array_key_exists("$city-miasto", $STOP_AGRESJI))
      return "$city-miasto";

    if(isset($address->county)) {
      $county = trimstr2lower($address->county);
      if(array_key_exists($county, $STOP_AGRESJI))
          return $county;
    }

    if (array_key_exists($voivodeship, $STOP_AGRESJI))
      return $voivodeship;

    return 'default';
  }

  public function isPolice(): bool
  {
    return true;
  }
}

?>
