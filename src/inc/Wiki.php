<?PHP namespace wiki;

/**
 * Renderer wiki: Markdown (league/commonmark) + allowlistowane dyrektywy. Redaktor nigdy nie pisze Twiga ani
 * surowego HTML (html_input: strip), każda dyrektywa mapuje się na stały partial src/templates/wiki/_directive-*.
 *
 * Składnia poza CommonMark/GFM:
 *  - `:::nazwa{klucz=wartość klucz2="z spacjami"}` … `:::` — blok dyrektywy (DIRECTIVES). Zagnieżdżona dyrektywa
 *    potrzebuje innej liczby dwukropków niż zewnętrzna (`::::howto` … `:::uwaga` … `:::` … `::::`).
 *  - `[[toc]]`, `[[data:sm-hints]]`, `[[jednostka:sm/klucz]]` — blok osadzony w osobnej linii (EMBEDS).
 *  - `## Nagłówek {#kotwica}` — stała kotwica (bez niej generowana z tekstu).
 *  - `![opis](media:nazwa)` — obrazek wyłącznie z allowlisty wiki_media.
 *  - `{{sex:klucz}}` — forma rodzajowa z SEXSTRINGS, podstawiana na końcu (personalize), więc HTML da się cache'ować.
 */

// Wymaga załadowanego Twig.php (initBareTwig) — ładować po nim, jak w src/index.php i tests/bootstrap.php.

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Parser\Block\FencedCodeParser;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/** Limit rozmiaru treści strony (bez limitu liczby zapisów). */
const MAX_BODY_BYTES = 200_000;

/**
 * Dyrektywy blokowe → partial + dozwolone atrybuty (typ walidacji). Nazwa spoza tej mapy zostaje zwykłym tekstem.
 * Typy: text (≤ 200 znaków), id (kotwica), url (ścieżka względna, https, tel:, mailto:), media (nazwa z wiki_media).
 */
const DIRECTIVES = [
    'uwaga' => ['template' => 'wiki/_directive-note.html.twig', 'attrs' => ['title' => 'text'], 'required' => []],
    'info' => ['template' => 'wiki/_directive-note.html.twig', 'attrs' => ['title' => 'text'], 'required' => []],
    'wzor' => ['template' => 'wiki/_directive-wzor.html.twig', 'attrs' => ['title' => 'text'], 'required' => ['title']],
    'howto' => [
        'template' => 'wiki/_directive-howto.html.twig',
        'attrs' => ['id' => 'id', 'title' => 'text', 'img' => 'media', 'cta' => 'url', 'cta_label' => 'text'],
        'required' => ['id', 'title'],
    ],
];

/** Bloki osadzane `[[rodzaj:argument]]`; dane dostarcza wywołujący (Renderer::$embeds), poza 'toc'. */
const EMBEDS = ['toc', 'data', 'jednostka'];

/** Kotwice zajęte przez layout/JS — redaktor nie może ich nadać. */
const RESERVED_IDS = ['main', 'header', 'footer', 'content', 'menu', 'nav', 'sidebar', 'toc'];

const SEX_OPEN = "\u{E000}";
const SEX_CLOSE = "\u{E001}";

/**
 * Slug ASCII z polskiego tekstu. Jawna mapa zamiast iconv //TRANSLIT (zależny od locale/libc), bo slugi jednostek
 * są zamrażane na zawsze.
 */
function slugify(string $text): string
{
    static $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z'];
    $text = mb_strtolower(strtr($text, $map));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

final class RenderResult
{
    /**
     * @param list<array{level:int,id:string,text:string}> $toc nagłówki h2–h4 (sidebar/spis treści)
     * @param list<string> $errors błędy treści (podgląd je pokazuje, zapis odrzuca)
     * @param list<string> $media użyte nazwy mediów
     */
    public function __construct(
        public readonly string $html,
        public readonly array $toc,
        public readonly array $errors,
        public readonly array $media,
    ) {}

    /** Podstawia formy rodzajowe `{{sex:…}}` dla zalogowanego (albo SEXSTRINGS['?']). */
    public function personalize(array $sex): RenderResult
    {
        $toc = array_map(fn($entry) => ['text' => personalize($entry['text'], $sex)] + $entry, $this->toc);
        return new RenderResult(personalize($this->html, $sex), $toc, $this->errors, $this->media);
    }
}

function personalize(string $html, array $sex): string
{
    return preg_replace_callback('/' . SEX_OPEN . '(\w+)' . SEX_CLOSE . '/u',
        fn($m) => htmlspecialchars($sex[$m[1]] ?? SEXSTRINGS['?'][$m[1]] ?? '', ENT_QUOTES), $html);
}

final class Renderer
{
    /** @var list<string> */
    private array $errors = [];
    /** @var list<array{level:int,id:string,text:string}> */
    private array $toc = [];
    /** @var list<string> */
    private array $media = [];

    /**
     * @param array<string, callable(string):?string> $embeds rodzaj → html dla `[[rodzaj:arg]]` (null = nieznany arg)
     * @param ?callable(string):?array $mediaResolver nazwa → wiersz wiki_media (domyślnie \wiki\getMedia)
     */
    public function __construct(private array $embeds = [], private $mediaResolver = null)
    {
        $this->mediaResolver ??= fn(string $name) => getMedia($name);
    }

    public function render(string $markdown): RenderResult
    {
        $this->errors = [];
        $this->toc = [];
        $this->media = [];

        if (strlen($markdown) > MAX_BODY_BYTES)
            $this->error('Treść jest za długa (limit ' . intdiv(MAX_BODY_BYTES, 1000) . ' kB).');

        $markdown = preg_replace_callback('/\{\{sex:(\w+)\}\}/u', function ($m) {
            if (!array_key_exists($m[1], SEXSTRINGS['?'])) {
                $this->error("Nieznana forma {{sex:{$m[1]}}}.");
                return $m[0];
            }
            return SEX_OPEN . $m[1] . SEX_CLOSE;
        }, $markdown);

        $html = (string)$this->converter()->convert($markdown);
        return new RenderResult($html, $this->toc, array_values(array_unique($this->errors)), $this->media);
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    private function converter(): MarkdownConverter
    {
        $env = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'attributes' => ['allow' => ['id']],
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new TableExtension());
        $env->addExtension(new StrikethroughExtension());
        $env->addExtension(new AttributesExtension());
        $env->addBlockStartParser(new DirectiveStartParser($this), 100);
        $env->addBlockStartParser(new EmbedStartParser($this), 100);
        $env->addRenderer(DirectiveBlock::class, new DirectiveRenderer($this));
        $env->addRenderer(EmbedBlock::class, new EmbedRenderer($this));
        // po AttributesListener (priorytet 0): kotwice są już przypięte do nagłówków
        $env->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed'], -10);
        return new MarkdownConverter($env);
    }

    /** Kotwice + spis treści, obrazki tylko z allowlisty, rel na linkach zewnętrznych, walidacja atrybutów dyrektyw. */
    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $usedIds = [];
        $images = [];
        foreach ($event->getDocument()->iterator() as $node) {
            if (!$node instanceof Heading && $node->data->has('attributes/id')) {
                $id = (string)$node->data->get('attributes/id');
                if (!$this->validId($id)) $node->data->remove('attributes/id');
                else $usedIds[$id] = true;
            }
            if ($node instanceof Heading) {
                $id = $this->headingId($node, $usedIds);
                $usedIds[$id] = true;
                $node->data->set('attributes/id', $id);
                if ($node->getLevel() >= 2 && $node->getLevel() <= 4)
                    $this->toc[] = ['level' => $node->getLevel(), 'id' => $id, 'text' => StringContainerHelper::getChildText($node)];
            } elseif ($node instanceof Image) {
                $images[] = $node;
            } elseif ($node instanceof Link) {
                $host = parse_url($node->getUrl(), PHP_URL_HOST);
                if ($host && !in_array(strtolower($host), [HOST, 'www.' . HOST], true))
                    $node->data->set('attributes/rel', 'nofollow ugc noopener');
            } elseif ($node instanceof DirectiveBlock) {
                $this->validateDirective($node, $usedIds);
                if (!$node->closed)
                    $this->error(":::{$node->name} nie jest zamknięta (brak linii " . str_repeat(':', $node->fenceLength) . ").");
            }
        }
        // Obrazki podmieniane po przejściu drzewa (modyfikacja w trakcie iteracji gubi węzły).
        foreach ($images as $image) {
            $media = $this->resolveMedia($image->getUrl(), 'Obrazek');
            if ($media) {
                $image->setUrl(mediaUrl($media));
                if ($media['width']) $image->data->set('attributes/width', (string)$media['width']);
                if ($media['height']) $image->data->set('attributes/height', (string)$media['height']);
                $image->data->set('attributes/loading', 'lazy');
            } else {
                $image->replaceWith(new Text(StringContainerHelper::getChildText($image)));
            }
        }
    }

    /** `media:nazwa` → wiersz wiki_media; inne źródła i nieznane nazwy to błąd. */
    public function resolveMedia(string $url, string $what): ?array
    {
        if (!str_starts_with($url, 'media:')) {
            $this->error("$what spoza biblioteki mediów ($url) — użyj media:nazwa.");
            return null;
        }
        $name = substr($url, 6);
        $media = ($this->mediaResolver)($name);
        if (!$media) {
            $this->error("Nieznany plik w bibliotece mediów: $name.");
            return null;
        }
        $this->media[] = $name;
        return $media;
    }

    public function embed(string $kind, string $arg): ?string
    {
        if ($kind === 'toc')
            return renderTemplate('wiki/_embed-toc.html.twig', ['toc' => $this->toc]);
        if (!isset($this->embeds[$kind])) {
            $this->error("Blok [[$kind]] nie jest dostępny na tej stronie.");
            return null;
        }
        $html = ($this->embeds[$kind])($arg);
        if ($html === null) $this->error("Nieznany blok [[$kind:$arg]].");
        return $html;
    }

    private function headingId(Heading $heading, array $usedIds): string
    {
        $id = $heading->data->get('attributes/id', null);
        if ($id !== null && !$this->validId((string)$id)) $id = null;
        if ($id === null) {
            $id = slugify(StringContainerHelper::getChildText($heading)) ?: 'sekcja';
            if (in_array($id, RESERVED_IDS, true)) $id = "sekcja-$id";
        }
        $unique = $id;
        for ($i = 2; isset($usedIds[$unique]); $i++) $unique = "$id-$i";
        return $unique;
    }

    /** Kotwica redaktora: małe litery (także polskie), cyfry, myślnik; nie z RESERVED_IDS. */
    private function validId(string $id): bool
    {
        if (preg_match('/^[\p{Ll}0-9][\p{Ll}0-9-]{0,63}$/u', $id) && !in_array($id, RESERVED_IDS, true))
            return true;
        $this->error("Niepoprawna kotwica #$id (dozwolone małe litery, cyfry i myślnik).");
        return false;
    }

    private function validateDirective(DirectiveBlock $block, array &$usedIds): void
    {
        $spec = DIRECTIVES[$block->name];
        $valid = [];
        foreach ($block->rawAttrs as $key => $value) {
            $type = $spec['attrs'][$key] ?? null;
            if ($type === null) {
                $this->error(":::{$block->name} nie ma atrybutu „{$key}”.");
            } elseif ($type === 'media') {
                // resolveMedia sam zgłasza błąd
                if ($media = $this->resolveMedia("media:$value", 'Obrazek')) $valid[$key] = mediaUrl($media);
            } elseif ($type === 'id') {
                // validId sam zgłasza błąd
                if ($this->validId($value)) $valid[$key] = $value;
                $usedIds[$value] = true;
            } elseif ($type === 'text' ? mb_strlen($value) <= 200 : isSafeUrl($value)) {
                $valid[$key] = $value;
            } else {
                $this->error(":::{$block->name}: niepoprawna wartość {$key}.");
            }
        }
        foreach ($spec['required'] as $key)
            if (!isset($valid[$key])) $this->error(":::{$block->name} wymaga atrybutu {$key}.");
        $block->attrs = $valid + array_fill_keys(array_keys($spec['attrs']), '');
    }
}

/** Link w atrybucie dyrektywy: ścieżka w serwisie, https, tel:, mailto: (Twig autoescape nie blokuje javascript:). */
function isSafeUrl(string $url): bool
{
    if (preg_match('#^/(?!/)#', $url)) return true;
    if (preg_match('#^https://[^\s/]+#i', $url)) return true;
    return (bool)preg_match('#^(tel:[0-9 +-]+|mailto:[^\s@]+@[^\s@]+)$#i', $url);
}

/** Publiczny URL pliku mediów (prefiks wiki/ w buckecie zdjęć). */
function mediaUrl(array $media): string
{
    return '/' . ltrim($media['b2_key'], '/');
}

function renderTemplate(string $template, array $context): string
{
    static $twig = null;
    $twig ??= \initBareTwig();
    return $twig->render($template, $context);
}

final class DirectiveBlock extends AbstractBlock
{
    /** @var array<string,string> po walidacji, z pustymi wartościami dla niepodanych atrybutów */
    public array $attrs = [];
    /** false = brak płotu zamykającego (dyrektywa objęła resztę strony) */
    public bool $closed = false;

    /** @param array<string,string> $rawAttrs */
    public function __construct(public readonly string $name, public readonly array $rawAttrs, public readonly int $fenceLength)
    {
        parent::__construct();
    }
}

final class EmbedBlock extends AbstractBlock
{
    public function __construct(public readonly string $kind, public readonly string $arg)
    {
        parent::__construct();
    }
}

final class DirectiveStartParser implements BlockStartParserInterface
{
    public function __construct(private Renderer $renderer) {}

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || $cursor->getNextNonSpaceCharacter() !== ':')
            return BlockStart::none();
        if (!preg_match('/^\s*(:{3,})([a-z][a-z0-9-]*)\s*(?:\{(.*)\})?\s*$/', $cursor->getRemainder(), $m))
            return BlockStart::none();
        if (!isset(DIRECTIVES[$m[2]])) {
            $this->renderer->error("Nieznana dyrektywa :::{$m[2]}.");
            return BlockStart::none();
        }
        $cursor->advanceToEnd();
        return BlockStart::of(new DirectiveParser(strlen($m[1]), $m[2], parseAttrs($m[3] ?? '')))->at($cursor);
    }
}

/** `klucz=wartość klucz="wartość ze spacjami"` → tablica. */
function parseAttrs(string $raw): array
{
    preg_match_all('/([a-z_]+)=(?:"([^"]*)"|\'([^\']*)\'|(\S+))/', $raw, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
    $attrs = [];
    foreach ($matches as $m)
        $attrs[$m[1]] = $m[2] ?? $m[3] ?? $m[4];
    return $attrs;
}

final class DirectiveParser extends AbstractBlockContinueParser
{
    private DirectiveBlock $block;

    public function __construct(private int $fenceLength, string $name, array $attrs)
    {
        $this->block = new DirectiveBlock($name, $attrs, $fenceLength);
    }

    public function getBlock(): DirectiveBlock
    {
        return $this->block;
    }

    public function isContainer(): bool
    {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return true;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        // Zamyka tylko płot o tej samej długości — zagnieżdżona dyrektywa używa innej liczby dwukropków;
        // `:::` wewnątrz bloku kodu (np. ściąga z dyrektywami) to treść kodu.
        if (!$activeBlockParser instanceof FencedCodeParser
            && preg_match('/^\s{0,3}(:{3,})\s*$/', $cursor->getRemainder(), $m) && strlen($m[1]) === $this->fenceLength) {
            $this->block->closed = true;
            return BlockContinue::finished();
        }
        return BlockContinue::at($cursor);
    }
}

final class EmbedStartParser implements BlockStartParserInterface
{
    public function __construct(private Renderer $renderer) {}

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented() || $cursor->getNextNonSpaceCharacter() !== '[')
            return BlockStart::none();
        if (!preg_match('/^\s*\[\[([a-z]+)(?::([a-z0-9_\/.-]+))?\]\]\s*$/', $cursor->getRemainder(), $m))
            return BlockStart::none();
        if (!in_array($m[1], EMBEDS, true)) {
            $this->renderer->error("Nieznany blok [[{$m[1]}]].");
            return BlockStart::none();
        }
        $cursor->advanceToEnd();
        return BlockStart::of(new EmbedParser($m[1], $m[2] ?? ''))->at($cursor);
    }
}

final class EmbedParser extends AbstractBlockContinueParser
{
    private EmbedBlock $block;

    public function __construct(string $kind, string $arg)
    {
        $this->block = new EmbedBlock($kind, $arg);
    }

    public function getBlock(): EmbedBlock
    {
        return $this->block;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        return BlockContinue::none();
    }
}

final class DirectiveRenderer implements NodeRendererInterface
{
    public function __construct(private Renderer $renderer) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        assert($node instanceof DirectiveBlock);
        return renderTemplate(DIRECTIVES[$node->name]['template'], [
            'kind' => $node->name,
            'attrs' => $node->attrs,
            'content' => $childRenderer->renderNodes($node->children()),
        ]);
    }
}

final class EmbedRenderer implements NodeRendererInterface
{
    public function __construct(private Renderer $renderer) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        assert($node instanceof EmbedBlock);
        return $this->renderer->embed($node->kind, $node->arg) ?? '';
    }
}

// ─── Logika domenowa (wspólna dla webu i przyszłego REST/MCP) ────────────────────────────────────────────────────

/** Ścieżka strony: małe litery ASCII, cyfry, myślnik; segmenty przez '/'. Segment zaczynający się od '_' = trasa systemowa. */
const PATH_RE = '#^[a-z0-9][a-z0-9-]{0,79}(/[a-z0-9][a-z0-9-]{0,79}){0,3}$#';

const MAX_TITLE_LENGTH = 150;
const MAX_SUMMARY_LENGTH = 200;

/**
 * Dlaczego użytkownik nie może edytować (null = może). Edytuje każdy zarejestrowany z co najmniej jednym wysłanym
 * zgłoszeniem (decyzja z planu, 3.5), o ile admin nie zablokował mu edycji (data->wikiBlocked).
 * @return null|'login'|'register'|'apps'|'blocked'
 */
function editBlockedReason(?\user\User $user): ?string
{
    if (!$user) return 'login';
    if (!$user->isRegistered()) return 'register';
    if (!empty($user->data->wikiBlocked)) return 'blocked';
    if (!$user->hasApps()) return 'apps';
    return null;
}

function canEdit(?\user\User $user): bool
{
    return editBlockedReason($user) === null;
}

/** Przywracanie wersji i zmiana ścieżki. */
function canModerate(?\user\User $user): bool
{
    return $user && $user->isModerator();
}

function isValidPath(string $path): bool
{
    return (bool)preg_match(PATH_RE, $path);
}

/** Renderer z blokami osadzanymi dostępnymi na każdej stronie wiki. */
function renderer(): Renderer
{
    return new Renderer();
}

/** Treść strony gotowa do wyświetlenia (formy rodzajowe dla zalogowanego lub neutralne). */
function renderForUser(string $markdown, array $sex): RenderResult
{
    return renderer()->render($markdown)->personalize($sex);
}

/**
 * Zapis edycji. $baseRev = current_rev strony w chwili otwarcia edytora — jeśli ktoś zapisał w międzyczasie,
 * zapis jest odrzucany (konflikt), zamiast cicho nadpisać cudzą zmianę.
 * @throws \ValidationException pole + komunikat (pole 'body' przy błędach treści, 'baseRev' przy konflikcie)
 * @throws \ForbiddenException
 */
function save(\user\User $user, string $path, string $title, string $body, string $summary, ?int $baseRev): int
{
    if (!canEdit($user))
        throw new \ForbiddenException('Nie masz uprawnień do edycji wiki.');
    if (!isValidPath($path))
        throw new \ValidationException('path', 'Niepoprawny adres strony (małe litery, cyfry, myślniki, segmenty przez „/”).');

    $title = cleanWhiteChars($title);
    $summary = cleanWhiteChars($summary);
    $body = str_replace("\r\n", "\n", $body);
    if ($title === '' || mb_strlen($title) > MAX_TITLE_LENGTH)
        throw new \ValidationException('title', 'Tytuł jest wymagany (do ' . MAX_TITLE_LENGTH . ' znaków).');
    if (mb_strlen($summary) > MAX_SUMMARY_LENGTH)
        throw new \ValidationException('summary', 'Opis zmiany może mieć do ' . MAX_SUMMARY_LENGTH . ' znaków.');

    $result = renderer()->render($body);
    if ($result->errors)
        throw new \ValidationException('body', implode("\n", $result->errors));

    $page = getPage($path);
    if ($page && (int)$page['current_rev'] !== (int)$baseRev)
        throw new \ValidationException('baseRev', 'Ktoś zapisał tę stronę w trakcie Twojej edycji. Skopiuj swoje zmiany i otwórz edytor ponownie.');
    if ($page && $page['title'] === $title && $page['body_md'] === $body)
        return (int)$page['current_rev'];

    $revId = savePage($path, $title, $body, $summary, $user->getEmail(), $user->getFirstName(), sectionOf($path));
    log_info("wiki: $path rev $revId by {$user->getEmail()}");
    return $revId;
}

/** Przywrócenie starszej wersji = nowa rewizja z jej treścią (historia zostaje liniowa). */
function revert(\user\User $user, int $revId): string
{
    if (!canModerate($user))
        throw new \ForbiddenException('Przywracać wersje może tylko moderator.');
    $rev = getRevision($revId);
    if (!$rev)
        throw new \ValidationException('rev', "Nie ma wersji $revId.");
    $page = pathById((int)$rev['page_id']);
    savePage($page, $rev['title'], $rev['body_md'], "Przywrócono wersję #$revId", $user->getEmail(), $user->getFirstName());
    return $page;
}

/** Sekcja z pierwszego segmentu ścieżki (prawo, wzory, pytania, sluzby) albo '' dla poradników. */
function sectionOf(string $path): string
{
    $first = explode('/', $path)[0];
    return str_contains($path, '/') && in_array($first, ['prawo', 'wzory', 'pytania', 'sluzby'], true) ? $first : '';
}

/**
 * Diff linii (LCS) do widoku historii. Strony mają po kilkaset linii, więc O(n·m) wystarcza.
 * @return list<array{op:'='|'+'|'-',line:string}>
 */
function diffLines(string $old, string $new): array
{
    $a = explode("\n", $old);
    $b = explode("\n", $new);
    $n = count($a);
    $m = count($b);
    $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--)
        for ($j = $m - 1; $j >= 0; $j--)
            $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);

    $ops = [];
    for ($i = 0, $j = 0; $i < $n || $j < $m;) {
        if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
            $ops[] = ['op' => '=', 'line' => $a[$i++]];
            $j++;
        } elseif ($i < $n && ($j >= $m || $lcs[$i + 1][$j] >= $lcs[$i][$j + 1])) {
            $ops[] = ['op' => '-', 'line' => $a[$i++]]; // usunięcie przed dodaniem, jak w diff -u
        } else {
            $ops[] = ['op' => '+', 'line' => $b[$j++]];
        }
    }
    return $ops;
}

/**
 * Poradnik redaktora (modal w edytorze): przykłady pokazywane jako kod + wynik tego samego renderera.
 * tests/wiki/GuideTest.php renderuje każdy przykład z 'render' => true — poradnik nie rozjedzie się z rendererem.
 * @return list<array{title:string,intro:string,examples:list<array{label:string,md:string,render:bool}>}>
 */
function guide(): array
{
    return [
        [
            'title' => 'Tekst i nagłówki',
            'intro' => 'Treść piszesz w Markdown. Tytuł strony ustawiasz w osobnym polu, więc w treści zaczynaj od nagłówków drugiego poziomu (##). Nagłówki ##–#### trafiają automatycznie do spisu treści.',
            'examples' => [
                ['label' => 'Nagłówki', 'md' => "## Rozdział\n\n### Podrozdział", 'render' => true],
                ['label' => 'Wyróżnienia', 'md' => "**pogrubienie**, *kursywa*, ~~skreślenie~~, `kod`", 'render' => true],
                ['label' => 'Listy', 'md' => "- punkt\n- drugi punkt\n\n1. pierwszy krok\n2. drugi krok", 'render' => true],
                ['label' => 'Cytat (np. przepis)', 'md' => "> Art. 49 § 1 pkt 1 Prawa o ruchu drogowym zabrania zatrzymania pojazdu…", 'render' => true],
            ],
        ],
        [
            'title' => 'Linki i kotwice',
            'intro' => 'Linkuj do innych stron wiki i UD ścieżką od ukośnika. Linki zewnętrzne dostają automatycznie rel="nofollow". Kotwica {#nazwa} przy nagłówku daje stały adres sekcji (małe litery, cyfry, myślnik) — bez niej adres powstaje z treści nagłówka i zmieni się, gdy go przeredagujesz.',
            'examples' => [
                ['label' => 'Link do innej strony wiki', 'md' => "Zobacz [jak napisać zażalenie](/wiki/zazalenie-na-decyzje-sm).", 'render' => true],
                ['label' => 'Link zewnętrzny', 'md' => "[Prawo o ruchu drogowym](https://isap.sejm.gov.pl/)", 'render' => true],
                ['label' => 'Stała kotwica sekcji', 'md' => "## Czy będę wezwany na świadka? {#swiadek}\n\nLink do niej: [przeczytaj](#swiadek).", 'render' => true],
                ['label' => 'Spis treści w treści strony', 'md' => "[[toc]]", 'render' => false],
            ],
        ],
        [
            'title' => 'Tabele',
            'intro' => 'Kolumny oddzielasz pionową kreską, drugi wiersz z myślnikami oddziela nagłówek.',
            'examples' => [
                ['label' => 'Tabela', 'md' => "| Kategoria | Przepis |\n|---|---|\n| Parkowanie na trawniku | art. 144 KW |\n| Za blisko przejścia | art. 49 PoRD |", 'render' => true],
            ],
        ],
        [
            'title' => 'Ramki: uwaga i informacja',
            'intro' => 'Blok zaczyna linia z trzema dwukropkami i nazwą, kończy linia z samymi trzema dwukropkami. Tytuł ramki jest opcjonalny.',
            'examples' => [
                ['label' => 'Uwaga', 'md' => ":::uwaga{title=\"Termin\"}\nZażalenie składasz w ciągu **7 dni** od doręczenia postanowienia.\n:::", 'render' => true],
                ['label' => 'Informacja', 'md' => ":::info\nTa straż przyjmuje zgłoszenia wyłącznie przez ePUAP.\n:::", 'render' => true],
            ],
        ],
        [
            'title' => 'Wzór pisma',
            'intro' => 'Wzór dostaje przycisk „Kopiuj”. Miejsca do uzupełnienia oznaczaj nawiasami kwadratowymi wielkimi literami, np. [ORGAN], [DATA].',
            'examples' => [
                ['label' => 'Wzór', 'md' => ":::wzor{title=\"Wniosek o informację publiczną\"}\n[MIEJSCOWOŚĆ], [DATA]\n\n[ORGAN]\n\nNa podstawie art. 2 ust. 1 ustawy o dostępie do informacji publicznej wnoszę o…\n:::", 'render' => true],
            ],
        ],
        [
            'title' => 'Karta metody (howto)',
            'intro' => 'Karta jak na stronie „Jak zgłosić nielegalne parkowanie”. Wymaga id (kotwica karty) i title; opcjonalnie img (nazwa pliku z biblioteki mediów), cta (adres przycisku: ścieżka, https://, tel: lub mailto:) i cta_label. Ramkę wewnątrz karty otwierasz inną liczbą dwukropków niż kartę.',
            'examples' => [
                ['label' => 'Karta z przyciskiem', 'md' => ":::howto{id=telefon title=\"Telefonicznie\" cta=\"tel:986\" cta_label=\"Zadzwoń pod 986\"}\n*Kiedy stosować:* na całych patoparkingach.\n:::", 'render' => true],
                ['label' => 'Ramka wewnątrz karty', 'md' => "::::howto{id=email title=\"E-mailem\"}\nTreść karty.\n\n:::uwaga\nRamka w karcie.\n:::\n::::", 'render' => true],
            ],
        ],
        [
            'title' => 'Obrazki',
            'intro' => 'Obrazki pochodzą wyłącznie z biblioteki mediów wiki — nie da się wstawić obrazka z innej strony. Potrzebujesz nowego zrzutu ekranu lub zdjęcia? Wyślij plik (PNG, JPEG lub WebP, do 1 MB) przez stronę kontaktu, a administrator doda go do biblioteki i poda nazwę.',
            'examples' => [
                ['label' => 'Obrazek z biblioteki', 'md' => "![Formularz ePUAP z zaznaczonym polem adresata](media:epuap-adresat.png)", 'render' => false],
            ],
        ],
        [
            'title' => 'Formy rodzajowe',
            'showSexKeys' => true,
            'intro' => 'Tekst może odmieniać się przez płeć czytającej osoby (dla niezalogowanych pokazuje się forma neutralna). Wpisz {{sex:klucz}} — listę kluczy znajdziesz niżej.',
            'examples' => [
                ['label' => 'Odmiana', 'md' => "Czy będę {{sex:anonimowa}}? Czy zostanę {{sex:wezwana}} na świadka?", 'render' => true],
            ],
        ],
        [
            'title' => 'Bloki z danymi',
            'intro' => 'Niektóre strony pokazują dane z konfiguracji UD zamiast tekstu wpisanego ręcznie — nie przepisuj ich do treści, bo się zdezaktualizują.',
            'examples' => [
                ['label' => 'Karta jednostki (SM/Policja)', 'md' => "[[jednostka:sm/wroclaw]]", 'render' => false],
                ['label' => 'Doświadczenia z przesłuchań', 'md' => "[[data:sm-hints]]", 'render' => false],
            ],
        ],
    ];
}

/** Zasady redakcyjne pokazywane w poradniku (lista punktów). */
const GUIDE_RULES = [
    'Pisz rzeczowo i neutralnie — wiki czytają też urzędnicy i strażnicy.',
    'Nie publikuj danych osobowych: imion i nazwisk funkcjonariuszy, numerów rejestracyjnych, adresów prywatnych, sygnatur spraw innych osób.',
    'Podawaj podstawę prawną (artykuł, ustawa) i, jeśli możesz, link do źródła.',
    'Nie kopiuj tekstów z innych serwisów — streść je własnymi słowami i podlinkuj.',
    'Każda zmiana trafia do historii z Twoim imieniem; moderator może przywrócić poprzednią wersję. Opisz krótko, co zmieniasz.',
    'Adresy e-mail jednostek pochodzą z konfiguracji UD — błędny adres zgłoś przez stronę kontaktu, nie poprawiaj go w notatce.',
];
