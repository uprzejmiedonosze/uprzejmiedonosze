<?PHP

namespace generator;

require_once(__DIR__ . '/JSONObject.php');

class Petition extends \JSONObject {
    protected const USE_ARRAY_FLOW = true;
    public string $id;
    public string $email;
    public array $topics;
    public string $formType;
    public string $target;
    public string $recipient;
    public string $added;
    public float $price;
    public string $status;
    public string $text;
    public string $systemPrompt;
    public string $contentPrompt;
    public string $sex;

    private function __construct() {
    }

    public static function withJson(string $json = null) {
        $instance = new self();
        $instance->__fromJson($json);
        return $instance;
    }

    public static function withData(
        array $topics,
        string $formType,
        string $target, 
        string $recipient,
        \user\user $user
    ) {

        $instance = new self();

        $instance->email = $user->getEmail();
        $instance->id = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
        $instance->added = date(DT_FORMAT);
        $instance->topics = $topics;
        $instance->formType = $formType;
        $instance->target = $target;
        $instance->recipient = $recipient;
        $instance->status = 'draft';
        $instance->sex = $user->getSexIdentifier();

        return $instance;
    }

    public function setGenerated(string $text, float $price): void {
        $this->text = $text;
        $this->price = $price;
        $this->status = 'generated';
    }

    public function generateSystemPrompt(): string {
        $sex = SEXSTRINGS[$this->sex];
        $this->systemPrompt = "Jesteś {$sex['mieszkanka']} powszechnym łamaniem przepisów związanych z parkowaniem przez kierowców";
        return $this->systemPrompt;
    }


    public function generateContentPrompt(): string {
        global $TOPICS, $TARGETS, $FORMS, $INTRO;

        $sex = SEXSTRINGS[$this->sex];

        $topicsStr = "";
        foreach ($this->topics as $topicId) {
            if (!isset($TOPICS[$topicId])) continue;

            $topic = $TOPICS[$topicId];
            $topicDesc = trim($topic['desc']);
            $topicsStr .= "\n\n## {$topic['title']}\n\n$topicDesc\n\n";

            $examples = $topic['examples'];
            if (!empty($examples) && is_array($examples)) {
                shuffle($examples);
                $examples = array_slice($examples, 0, 3);
                $topicsStr .= "Przykładowe efekty wadliwych przepisów:\n";
                $topicsStr .= "  - " . implode("\n  - ", $examples);
            }

            if ($this->formType === 'proposal' && !empty($topic['law'])) {
                $topicsStr .= "\n\n### Propozycja zmiany przepisów:\n\n{$topic['law']}";
            }
        }

        $topicsStr = trim($topicsStr);

        $targetTitle = $TARGETS[$this->target]['title'] ?? $this->target;
 
        // do we know more about the recipient?
        $additionalRecipientData = "";
        if (!empty($this->recipient)) {
          // this should be already validated, but let's check anyway
          $mps = \json\get('parlamentary.json');
          if (isset($mps[$this->recipient])) {
            $targetTitle = 'Członka Sejmowej Komisji Infrastruktury (mężczyzna)';
            $name = ApiAiHandler::changeNameOrder($this->recipient);
            if (\user\User::_guessSex($name) == 'f')
                $targetTitle = 'Członkini Sejmowej Komisji Infrastruktury (kobieta)';
          };
        };
        $intro = $this->formType !== 'email' ? "# Pozostałe\n\nMożesz użyć także tych materiałów:\n\n{$INTRO}" : "";

        $formText = $FORMS[$this->formType] ?? $this->formType;
        $this->contentPrompt = <<<EOD
# Zadanie

Napisz $formText do $targetTitle w sprawie wadliwych przepisów regulujących parkowanie w Polsce.
$additionalRecipientData

# Uwagi ogólne

1. Używaj przykładów i propozycji podanych poniżej. Nie wymyślaj własnych propozycji.
2. Nie stosuj formatowania markdown albo ikon. Czysty tekst.
3. Pisz w pierwszej osobie liczby pojedynczej rodzaju {$sex['żeńskiego']}.
4. Pisz w stylu oficjalnym, ale nie przesadnie formalnym.
5. Nie pisz adresata w mailu (np. "Szanowny $targetTitle"). Sam go dopiszę.
6. Napisz tytuł wiadomości w pierszej linijce, w formacie "Temat: ...". Potem pusta linia o treść pisma.
7. Nie podpisuj tego dokumentu - sam to uzupełnię. Nie pisz także placeholdera typu [mieszkaniec] itp.

# Tematy do uwzględnienia

$topicsStr

$intro
EOD;

        return $this->contentPrompt;
    }
}
