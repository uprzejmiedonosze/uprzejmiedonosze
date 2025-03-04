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
     * @SuppressWarnings(PHPMD.CamelCaseVariableName)
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public static function guess(object $address): string { // straż miejska
        global $SM_ADDRESSES;

        // post code level
        if(isset($address->postcode)) {
            $postcode = $address->postcode;
            if(array_key_exists($postcode, $SM_ADDRESSES))
                return $postcode;
        }

        // city level
        $city = trimstr2lower($address->city ?? '');
        if(array_key_exists($city, $SM_ADDRESSES)){
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
            if(array_key_exists($county, $SM_ADDRESSES))
                return $county;

            // guessed county level (remove 'gmina ' from county name)
            $county = str_replace('gmina ', '', $county);
            if(array_key_exists($county, $SM_ADDRESSES))
                return $county;
        }

        // municipality level | powiat
        if(isset($address->municipality)) {
            $municipality = trimstr2lower($address->municipality);
            if(array_key_exists($municipality, $SM_ADDRESSES))
                return $municipality;
        }
        return '_nieznane';
    }
}
