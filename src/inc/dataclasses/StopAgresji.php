<?PHP

require_once(__DIR__ . '/SM.php');
require_once(__DIR__ . '/Police.php');

class StopAgresji extends SM {
  public function unknown(): bool {
    return false;
  }

  public function getCity(): string {
    return "Stop Agresji Drogowej " . $this->city;
  }

  public function getHint(): ?string {
    return $this->hint ?? ('Nie mamy jeszcze relacji ze współpracy z ' . $this->address[0]);
  }

  /**
   * Najpierw próbuje znaleźć konkretny komisariat Policji (Police::guess()).
   * Jeśli nic nie znajdzie, spada na poziom wojewódzki (gwarantowany fallback).
   * @SuppressWarnings(PHPMD.MissingImport)
   * @SuppressWarnings(PHPMD.CamelCaseVariableName)
   */
  public static function guess(object $address): string  { // stop agresji
    if ($key = \Police::guess($address))
      return $key;
    return self::guessVoivodeship($address);
  }

  /**
   * Wojewódzka (albo miejska, np. KSP) skrzynka „Stop agresji drogowej”, z pominięciem komisariatów.
   * Tu trafiają zgłoszenia „na gorąco” (Category::isAdHoc()), także z nagraniem wideo.
   * @SuppressWarnings(PHPMD.CamelCaseVariableName)
   */
  public static function guessVoivodeship(object $address): string {
    global $STOP_AGRESJI;

    // a handful of city-level aliases (e.g. Warszawa) live directly in
    // stop-agresji.json rather than police.json, so Police::guess() alone
    // can't find them.
    $city = trimstr2lower($address->city ?? '');
    if (array_key_exists("$city-miasto", $STOP_AGRESJI))
      return "$city-miasto";

    $voivodeship = trimstr2lower($address->voivodeship ?? '');
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
