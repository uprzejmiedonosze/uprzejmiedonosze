<?php

require_once(__DIR__ . '/JSONObject.php');

/**
 * Straz Miejska class LOL.
 * @SuppressWarnings(PHPMD.ShortClassName)
 */
class SM extends JSONObject {
    protected const USE_ARRAY_FLOW = true;

    public array $address;
    public ?string $email;
    public ?string $city;
    public ?string $hint;
    public ?string $api;

    public function getAddress(): array {
        return $this->address;
    }

    public function getLatexAddress(): string {
        return implode(' \\\\ ', $this->address);
    }

    public function getEmail(): ?string {
        return $this->email;
    }

    public function getCity(): string {
        return "SM " . $this->city;
    }

    public function getHint(): ?string {
        return $this->hint;
    }

    public function getName(): string {
        return $this->address[0];
    }

    public function getShortName(): string {
        return strtr($this->address[0], [
            'Straż Miejska' => 'SM',
            'Straż Gminna' => 'SG',
            'Komenda Powiatowa Policji' => 'KPP',
            'Komenda Powiatowa' => 'KPP',
            'Komenda Miejska' => 'KMP',
            'Komisariat Policji' => 'KP',
            'Posterunek Policji' => 'PP',
            'Komenda Wojewódzka Policji' => 'KWP'
        ]);
    }

    public function hasAPI(): bool {
        return $this->api && !str_contains(strtolower($this->api), 'mail');
    }

    public function automated(): bool {
        return (bool) $this->api;
    }

    public function unknown(): bool {
        return $this->city === null;
    }

    public function isPolice(): bool
    {
        return str_contains($this->getEmail() ?? '', 'policja');
    }

    /**
     * Will SM/Police request a user to visit them?
     * @return bool
     */
    public function isQuestioning(): bool
    {
        return stripos($this->getHint() ?? '', 'nie wzyw') === false;
    }

    /**
     * Sprawdza, czy klucz istnieje w którejkolwiek z podanych tabel.
     * Współdzielone przez SM::guess() i Police::guess() - różnią się tylko
     * zestawem tabel przeszukiwanych na każdym poziomie szczegółowości.
     */
    protected static function matchesAnyTable(string $key, array ...$tables): bool {
        foreach ($tables as $table) {
            if (array_key_exists($key, $table)) return true;
        }
        return false;
    }

    /**
     * Szuka jednostki na każdym poziomie najpierw w $SM_ADDRESSES, a jeśli nie
     * znajdzie, także w $POLICE_ADDRESSES (gminy bez SM mają tam podstawiony
     * lokalny komisariat) — dopiero potem przechodzi do kolejnego poziomu.
     * To zachowuje dokładnie dawną semantykę "pierwsze trafienie wygrywa, w
     * kolejności poziomów", z czasów gdy obie kategorie żyły w jednym pliku.
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public static function guess(object $address): ?string { // straż miejska
        global $SM_ADDRESSES, $POLICE_ADDRESSES;

        // post code level
        if(isset($address->postcode)) {
            $postcode = $address->postcode;
            if(self::matchesAnyTable($postcode, $SM_ADDRESSES, $POLICE_ADDRESSES))
                return $postcode;
        }

        // city level
        $city = trimstr2lower($address->city ?? '');
        if(self::matchesAnyTable($city, $SM_ADDRESSES, $POLICE_ADDRESSES)){
            $smCity = $city;
            if($city == 'warszawa' && isset($address->district)){
                if(array_key_exists($address->district, ODDZIALY_TERENOWE)){
                    $smCity = ODDZIALY_TERENOWE[$address->district];
                }
            }
            return $smCity;
        }

        // county level | gmina
        if(isset($address->county)) {
            $county = trimstr2lower($address->county);
            if(self::matchesAnyTable($county, $SM_ADDRESSES, $POLICE_ADDRESSES))
                return $county;

            // guessed county level (remove 'gmina ' from county name)
            $county = str_replace('gmina ', '', $county);
            if(self::matchesAnyTable($county, $SM_ADDRESSES, $POLICE_ADDRESSES))
                return $county;
        }

        // municipality level | powiat
        if(isset($address->municipality)) {
            $municipality = trimstr2lower($address->municipality);
            if(self::matchesAnyTable($municipality, $SM_ADDRESSES, $POLICE_ADDRESSES))
                return $municipality;
        }
        return '_nieznane';
    }

    /**
     * Rozwiązuje zbuforowany klucz `smCity` na obiekt jednostki. Klucz mógł
     * historycznie powstać przed podziałem sm.json/police.json/stop-agresji.json
     * na trzy pliki, więc sprawdza alternatywny plik jako fallback.
     */
    public static function resolve(string $key, bool $stopAgresji): \SM {
        global $SM_ADDRESSES, $POLICE_ADDRESSES, $STOP_AGRESJI;
        if ($stopAgresji) {
            return $POLICE_ADDRESSES[$key] ?? $STOP_AGRESJI[$key] ?? $STOP_AGRESJI['default'];
        }
        return $SM_ADDRESSES[$key] ?? $POLICE_ADDRESSES[$key] ?? $SM_ADDRESSES['_nieznane'];
    }
}
