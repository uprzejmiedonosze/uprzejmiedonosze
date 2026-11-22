<?PHP

require_once(__DIR__ . '/JSONObject.php');

/**
 * Category class.
 */
class Category extends JSONObject{
    /**
     * Inits new Category from JSON.
     */
    public function __construct($json = null) {
        parent::__construct($json);
    }

    public function getTitle(){
        return $this->title;
    }

    public function getShort(){
        return $this->short ?? $this->informal;
    }

    public function getFormal(){
        return $this->formal;
    }

    /**
     * Formal wording for a report whose author did not witness the violation
     * itself (e.g. only the effect of driving along a sidewalk). Falls back
     * to the regular formal wording for categories without a variant.
     */
    public function getFormalNotWitness(){
        return $this->formalNotWitness ?? $this->formal;
    }

    public function getInformal(){
        return $this->informal;
    }

    public function getDesc(){
        return $this->descs;
    }

    public function getLaw(){
        return $this->law;
    }

    public function getPrice(){
        return $this->price;
    }

    public function getMandate(){
        return $this->mandate;
    }

    public function getPoints(){
        return $this->points;
    }

    /** Dokąd kategoria kieruje zgłoszenie – patrz recipientPolicy(). */
    public const POLICY_SM = 'sm';
    public const POLICY_POLICE = 'police';
    public const POLICY_VOIVODESHIP = 'voivodeship';

    /** Surowa flaga z categories.json; do rozstrzygania adresata służy recipientPolicy(). */
    public function isStopAgresjiOnly(){
        return $this->stopAgresjiOnly ?? false;
    }

    /**
     * Jedno źródło prawdy o adresacie narzuconym przez kategorię (używają go API, MCP i Application::guessSMData(); klienty
     * dostają wynik w `recipient.policy`):
     * - `voivodeship` – `requiresVideo` (hałas, drift): zawsze wojewódzka (albo miejska) skrzynka „Stop agresji drogowej”, z pominięciem komisariatu;
     * - `police` – `stopAgresjiOnly`: Policja (Stop Agresji): najbliższy komisariat, a gdy go nie ma, skrzynka wojewódzka;
     * - `sm` – bez narzucenia: wybór użytkownika (SM albo Policja).
     * Flagi w categories.json zostają (używa ich web i aplikacja); `requiresVideo` ma pierwszeństwo przed `stopAgresjiOnly`.
     */
    public function recipientPolicy(): string {
        if ($this->requiresVideo())
            return self::POLICY_VOIVODESHIP;
        return $this->isStopAgresjiOnly() ? self::POLICY_POLICE : self::POLICY_SM;
    }

    /** Czy kategoria wyklucza Straż Miejską (policja albo skrzynka wojewódzka). */
    public function forcesPolice(): bool {
        return $this->recipientPolicy() !== self::POLICY_SM;
    }

    /**
     * Kategoria jazdy (hałas, drift): zgłoszenie wymaga nagrania wideo (POST /api/rest/app/{id}/video, tylko aplikacja
     * mobilna – web i MCP jej nie pokazują) i trafia zawsze na wojewódzką skrzynkę „Stop agresji drogowej”.
     */
    public function requiresVideo(): bool {
        return (bool)($this->requiresVideo ?? false);
    }

    /**
     * Czy kategoria dotyczy parkowania – tylko wtedy treść zgłoszenia mówi o świadku „samego momentu parkowania”.
     */
    public function isParking(): bool {
        return (bool)($this->parking ?? true);
    }
}
