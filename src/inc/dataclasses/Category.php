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

    public function isStopAgresjiOnly(){
        return $this->stopAgresjiOnly;
    }

    /**
     * Zgłoszenie „na gorąco” (aplikacja mobilna): data z chwili zgłoszenia, zdjęcia nieobowiązkowe (może być nagranie
     * wideo albo sama relacja świadka), wysyłka zawsze na wojewódzką skrzynkę „Stop agresji drogowej”.
     * Takie kategorie nie mają `groups`, więc nie pojawiają się na zwykłej liście (web, starsze wersje aplikacji).
     */
    public function isAdHoc(): bool {
        return (bool)($this->adHoc ?? false);
    }

    /**
     * Kategoria w fazie testów: dostępna tylko poza produkcją. Grupy takiej kategorii leżą w `betaGroups`
     * (a nie w `groups`), żeby wydane już wersje aplikacji i web jej nie pokazywały.
     */
    public function isBeta(): bool {
        return (bool)($this->beta ?? false);
    }

    /**
     * Czy kategorie testowe (`beta`, w tym zgłoszenia „na gorąco”) są włączone – na razie tylko poza produkcją, gdzie
     * MailGun i tak wysyła zgłoszenia na adres testowy zamiast do Policji. Klienci dostają to w `features.adHocReports`
     * (GET /api/rest/user).
     */
    public static function betaEnabled(): bool {
        return !isProd();
    }

    /** Czy kategorię można dziś wybrać w zgłoszeniu (beta tylko gdy betaEnabled()). */
    public function isAvailable(): bool {
        return !$this->isBeta() || self::betaEnabled();
    }

    /**
     * Czy kategoria dotyczy parkowania – tylko wtedy treść zgłoszenia mówi o świadku „samego momentu parkowania”.
     */
    public function isParking(): bool {
        return (bool)($this->parking ?? true);
    }
}
