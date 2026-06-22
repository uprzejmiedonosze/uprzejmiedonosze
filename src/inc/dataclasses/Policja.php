<?PHP

require_once(__DIR__ . '/SM.php');
require_once(__DIR__ . '/PoliceStationAreas.php');

/**
 * Regular Police unit (komisariat/KPP/KMP/posterunek) used either as a
 * substitute for Straż Miejska, or as a specific local unit for #stopagresji
 * reports (before falling back to the voivodeship-level StopAgresji entry).
 */
class Policja extends SM {
    public function getCity(): string {
        return "Policja " . $this->city;
    }

    public function isPolice(): bool {
        return true;
    }

    /**
     * Zwraca najlepiej pasujący komisariat Policji dla adresu, albo null
     * jeśli nie znaleziono niczego bardziej konkretnego niż województwo
     * (wtedy StopAgresji::guess() kontynuuje na poziomie wojewódzkim).
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    public static function guess(object $address): ?string {
        global $POLICJA_ADDRESSES;

        $city = trimstr2lower($address->city ?? '');

        // this is a complex algorithm and runs on three cities at the moment
        // it's better to run it only when needed
        if (in_array($city, ['szczecin', 'kraków', 'poznań']) && ($address->lat ?? null) && ($address->lng ?? null)) {
            $policeStationAreas = new \PoliceStationAreas;
            $byCoords = $policeStationAreas->guess($address->lat, $address->lng);
            if ($byCoords) return $byCoords;
        }

        if (array_key_exists("$city-miasto", $POLICJA_ADDRESSES))
            return "$city-miasto";

        // post code level
        if (isset($address->postcode)) {
            $postcode = $address->postcode;
            if (array_key_exists($postcode, $POLICJA_ADDRESSES))
                return $postcode;
        }

        // city level
        if (array_key_exists($city, $POLICJA_ADDRESSES))
            return $city;

        // county level | gmina
        if (isset($address->county)) {
            $county = trimstr2lower($address->county);
            if (array_key_exists($county, $POLICJA_ADDRESSES))
                return $county;

            // guessed county level (remove 'gmina ' from county name)
            $county = str_replace('gmina ', '', $county);
            if (array_key_exists($county, $POLICJA_ADDRESSES))
                return $county;
        }

        // municipality level | powiat
        if (isset($address->municipality)) {
            $municipality = trimstr2lower($address->municipality);
            if (array_key_exists($municipality, $POLICJA_ADDRESSES))
                return $municipality;
        }

        return null;
    }
}
