<?PHP namespace wiki;

/**
 * Renderer wiki: Markdown (league/commonmark) + allowlistowane dyrektywy. Redaktor nigdy nie pisze Twiga ani
 * surowego HTML (html_input: strip), każda dyrektywa mapuje się na stały partial src/templates/wiki/_directive-*.
 *
 * Składnia poza CommonMark/GFM:
 *  - `:::nazwa{klucz=wartość klucz2="z spacjami"}` … `:::` — blok dyrektywy (DIRECTIVES). Zagnieżdżona dyrektywa
 *    potrzebuje innej liczby dwukropków niż zewnętrzna (`::::howto` … `:::uwaga` … `:::` … `::::`).
 *  - `[[toc]]`, `[[metody]]`, `[[data:sm-hints]]`, `[[jednostka:sm/klucz]]` — blok osadzony w osobnej linii (EMBEDS).
 *  - ```mermaid — diagram (Mermaid, securityLevel 'strict', src/js/sites/wiki.js).
 *  - `## Nagłówek {#kotwica}` — stała kotwica (bez niej generowana z tekstu).
 *  - `![opis](media:nazwa)` — obrazek wyłącznie z biblioteki mediów (pliki src/img/wiki/, mediaFile()).
 *  - `{{sex:klucz}}` — forma rodzajowa z SEXSTRINGS, podstawiana na końcu (personalize), więc HTML da się cache'ować.
 */

// Wymaga załadowanego Twig.php (initBareTwig) — ładować po nim, jak w src/index.php i tests/bootstrap.php.

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Renderer\Block\FencedCodeRenderer;
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
 * Typy: text (≤ 200 znaków), id (kotwica), url (ścieżka względna, https, tel:, mailto:), media (nazwa pliku z biblioteki mediów).
 */
const DIRECTIVES = [
    'uwaga' => ['template' => 'wiki/_directive-note.html.twig', 'attrs' => ['title' => 'text'], 'required' => []],
    'info' => ['template' => 'wiki/_directive-note.html.twig', 'attrs' => ['title' => 'text'], 'required' => []],
    'wzor' => ['template' => 'wiki/_directive-wzor.html.twig', 'attrs' => ['title' => 'text'], 'required' => ['title']],
    'howto' => [
        'template' => 'wiki/_directive-howto.html.twig',
        // method + summary: wiersz w [[metody]]; img_top: obrazek kadrowany od góry (klasa move-top)
        'attrs' => ['id' => 'id', 'title' => 'text', 'img' => 'media', 'img_top' => 'bool', 'cta' => 'url', 'cta_label' => 'text',
            'method' => 'text', 'summary' => 'text'],
        'required' => ['id', 'title'],
    ],
];

/** Bloki osadzane `[[rodzaj:argument]]`; dane dostarcza wywołujący (Renderer::$embeds), poza 'toc'. */
const EMBEDS = ['toc', 'metody', 'data', 'jednostka'];

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
    /** @var list<array{id:string,label:string,summary:string}> karty :::howto z `summary` — dla [[metody]] */
    private array $methods = [];

    /**
     * @param array<string, callable(string):?string> $embeds rodzaj → html dla `[[rodzaj:arg]]` (null = nieznany arg)
     * @param ?callable(string):?array $mediaResolver nazwa → {url,width,height} (domyślnie \wiki\mediaFile)
     */
    public function __construct(private array $embeds = [], private $mediaResolver = null)
    {
        $this->mediaResolver ??= fn(string $name) => mediaFile($name);
    }

    public function render(string $markdown): RenderResult
    {
        $this->errors = [];
        $this->toc = [];
        $this->media = [];
        $this->methods = [];

        if (strlen($markdown) > MAX_BODY_BYTES) {
            $this->error('Treść jest za długa (limit ' . intdiv(MAX_BODY_BYTES, 1000) . ' kB).');
            return new RenderResult('', [], $this->errors, []);
        }

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
        $env->addExtension(new AutolinkExtension());
        $env->addRenderer(FencedCode::class, new MermaidAwareCodeRenderer(), 10);
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
                $image->setUrl($media['url']);
                if ($media['width']) $image->data->set('attributes/width', (string)$media['width']);
                if ($media['height']) $image->data->set('attributes/height', (string)$media['height']);
                $image->data->set('attributes/loading', 'lazy');
            } else {
                $image->replaceWith(new Text(StringContainerHelper::getChildText($image)));
            }
        }
    }

    /** `media:nazwa` → plik z biblioteki mediów; inne źródła i nieznane nazwy to błąd. */
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
        if ($kind === 'metody')
            return renderTemplate('wiki/_embed-methods.html.twig', ['methods' => $this->methods]);
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
                if ($media = $this->resolveMedia("media:$value", 'Obrazek')) $valid[$key] = $media['url'];
            } elseif ($type === 'id') {
                // validId sam zgłasza błąd
                if ($this->validId($value)) $valid[$key] = $value;
                $usedIds[$value] = true;
            } elseif ($type === 'bool') {
                $valid[$key] = in_array(mb_strtolower($value), ['1', 'tak', 'true'], true) ? '1' : '';
            } elseif ($type === 'text' ? mb_strlen($value) <= 200 : isSafeUrl($value)) {
                $valid[$key] = $value;
            } else {
                $this->error(":::{$block->name}: niepoprawna wartość {$key}.");
            }
        }
        foreach ($spec['required'] as $key)
            if (!isset($valid[$key])) $this->error(":::{$block->name} wymaga atrybutu {$key}.");
        $block->attrs = $valid + array_fill_keys(array_keys($spec['attrs']), '');
        if ($block->name === 'howto' && $block->attrs['summary'] !== '' && $block->attrs['id'] !== '')
            $this->methods[] = ['id' => $block->attrs['id'], 'label' => $block->attrs['method'] ?: $block->attrs['title'], 'summary' => $block->attrs['summary']];
    }
}

/** Link w atrybucie dyrektywy: ścieżka w serwisie, https, tel:, mailto: (Twig autoescape nie blokuje javascript:). */
function isSafeUrl(string $url): bool
{
    if (preg_match('#^/(?!/)#', $url)) return true;
    if (preg_match('#^https://[^\s/]+#i', $url)) return true;
    return (bool)preg_match('#^(tel:[0-9 +-]+|mailto:[^\s@]+@[^\s@]+)$#i', $url);
}

/** Media wiki: pliki w src/img/wiki/ (→ export/public/img/wiki/, adres /img/wiki/<nazwa>), dodawane przez admina commitem. */
const MEDIA_DIR = __DIR__ . '/../public/img/wiki';

/** Plik z biblioteki mediów albo null (nieznana nazwa = błąd w podglądzie). @return ?array{url:string,width:?int,height:?int} */
function mediaFile(string $name): ?array
{
    if (!preg_match('/^[a-z0-9][a-z0-9._-]*\.(png|jpe?g|webp|gif)$/', $name) || !is_file(MEDIA_DIR . "/$name"))
        return null;
    $size = @getimagesize(MEDIA_DIR . "/$name") ?: [null, null];
    return ['url' => "/img/wiki/$name", 'width' => $size[0], 'height' => $size[1]];
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
        if (!preg_match('/^\s*\[\[([a-z]+)(?::([\p{L}0-9_\/. -]+))?\]\]\s*$/u', $cursor->getRemainder(), $m))
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

/** ```mermaid → <pre class="mermaid"> (tekst escapowany; rysuje src/js/sites/wiki.js), reszta jak zwykły blok kodu. */
final class MermaidAwareCodeRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string|null
    {
        assert($node instanceof FencedCode);
        if (trim($node->getInfo() ?? '') !== 'mermaid')
            return (new FencedCodeRenderer())->render($node, $childRenderer);
        return '<pre class="mermaid">' . htmlspecialchars($node->getLiteral(), ENT_QUOTES) . '</pre>';
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

/**
 * Strony www, których treść redaktorzy edytują w wiki (wiki_pages.route_alias = trasa). Adres www zostaje; szablon
 * pokazuje treść z wiki, a bez niej (przed importem) dotychczasową treść z szablonu.
 */
const WWW_PAGES = ['faq', 'przesluchanie', 'jak-zglosic-nielegalne-parkowanie'];

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
    return new Renderer([
        'data' => fn(string $arg): ?string => $arg === 'sm-hints'
            ? renderTemplate('wiki/_embed-sm-hints.html.twig', ['hints' => smHints()]) : null,
        'jednostka' => function (string $arg): ?string {
            [$type, $key] = array_pad(explode('/', $arg, 2), 2, '');
            $entry = isset(UNIT_TYPES[$type]) ? unitByKey($type, $key) : null;
            return $entry ? unitCard($entry) : null;
        },
    ]);
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
    if (str_starts_with($path, 'sluzby/') && !unitBySlug(substr($path, 7)))
        throw new \ValidationException('path', 'Strony w sekcji sluzby to notatki jednostek z konfiguracji — nie ma takiej jednostki.');

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

/**
 * cleanID z DokuWiki (deaccent = 1): małe litery, polskie znaki bez ogonków, '/' i ' ' → ':' / '_',
 * reszta niedozwolonych znaków → '_'. Wejście może być ścieżką z URL-a, linkiem z treści albo nazwą pliku.
 */
function dokuCleanId(string $id): string
{
    $id = rawurldecode(trim($id));
    $id = mb_strtolower(strtr($id, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z']));
    $id = str_replace(['/', ';'], ':', $id);
    $id = preg_replace('/[^a-z0-9:._-]+/', '_', $id);
    $id = preg_replace('/_+/', '_', $id);
    $id = preg_replace('/:+/', ':', $id);
    return trim($id, ':._-');
}

/** Nazwa pliku w bibliotece wiki dla starego id medium DokuWiki: sam basename (przestrzenie nazw znikają), małe litery. */
function dokuMediaName(string $mediaId): string
{
    $base = basename(str_replace(':', '/', dokuCleanId(preg_replace('/\?.*$/', '', $mediaId))));
    return preg_replace('/[^a-z0-9._-]/', '-', $base);
}

/** Ścieżka wiki odpowiadająca staremu id DokuWiki (forma, pod którą zapisane są wiki_redirects). */
function legacyPath(string $dokuId): string
{
    return str_replace(':', '/', dokuCleanId($dokuId));
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

/** Górna granica tablicy LCS (n·m) — powyżej diff jest pomijany, żeby jedna edycja nie wyczerpała pamięci. */
const DIFF_MAX_CELLS = 1_000_000;

/**
 * Diff linii (LCS) do widoku historii. Wspólny początek i koniec są odcinane przed LCS, więc typowa edycja
 * (kilka linii) kosztuje mało niezależnie od długości strony. null = zmiana zbyt duża do pokazania.
 * @return ?list<array{op:'='|'+'|'-',line:string}>
 */
function diffLines(string $old, string $new): ?array
{
    $a = explode("\n", $old);
    $b = explode("\n", $new);
    $head = [];
    while ($a && $b && $a[0] === $b[0]) {
        $head[] = ['op' => '=', 'line' => array_shift($a)];
        array_shift($b);
    }
    $tail = [];
    while ($a && $b && end($a) === end($b)) {
        array_unshift($tail, ['op' => '=', 'line' => array_pop($a)]);
        array_pop($b);
    }
    $n = count($a);
    $m = count($b);
    if (($n + 1) * ($m + 1) > DIFF_MAX_CELLS)
        return null;
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
    return array_merge($head, $ops, $tail);
}

/**
 * Poradnik redaktora (modal w edytorze): przykłady pokazywane jako kod + wynik tego samego renderera.
 * tests/wiki/GuideTest.php renderuje każdy przykład z 'render' => true — poradnik nie rozjedzie się z rendererem.
 * 'render' => false: przykład nie da się pokazać poza kontekstem (brak pliku w bibliotece, blok tylko dla jednej strony);
 * 'insert' => false: bez przycisku „Wstaw”, bo na zwykłej stronie zapis zostałby odrzucony.
 * @return list<array{title:string,intro:string,examples:list<array{label:string,md:string,render:bool,note?:string,insert?:bool}>}>
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
                ['label' => 'Spis treści w treści strony (zwykle zbędny — spis jest w panelu bocznym)', 'md' => "[[toc]]", 'render' => false],
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
            'intro' => 'Karta jak na stronie „Jak zgłosić nielegalne parkowanie”. Wymaga id (kotwica karty) i title; opcjonalnie img (nazwa pliku z biblioteki mediów), img_top=1 (kadr od góry), cta (adres przycisku: ścieżka, https://, tel: lub mailto:) i cta_label. Karty z summary (i opcjonalnie krótszą nazwą method) trafiają do listy [[metody]]. Ramkę wewnątrz karty otwierasz inną liczbą dwukropków niż kartę.',
            'examples' => [
                ['label' => 'Lista metod z kart na tej stronie', 'md' => "[[metody]]\n\n:::howto{id=sm-telefon title=\"Telefonicznie\" method=\"Telefon\" summary=\"na całe patoparkingi\"}\nTreść karty.\n:::", 'render' => true],
                ['label' => 'Karta z przyciskiem', 'md' => ":::howto{id=telefon title=\"Telefonicznie\" cta=\"tel:986\" cta_label=\"Zadzwoń pod 986\"}\n*Kiedy stosować:* na całych patoparkingach.\n:::", 'render' => true],
                ['label' => 'Ramka wewnątrz karty', 'md' => "::::howto{id=email title=\"E-mailem\"}\nTreść karty.\n\n:::uwaga\nRamka w karcie.\n:::\n::::", 'render' => true],
            ],
        ],
        [
            'title' => 'Diagramy',
            'intro' => 'Schemat procesu rysujesz w składni Mermaid (https://mermaid.js.org) w bloku kodu oznaczonym mermaid. Podgląd pokaże gotowy diagram.',
            'examples' => [
                ['label' => 'Schemat blokowy', 'md' => "```mermaid\nflowchart LR\n    zgloszenie([Zgłoszenie]) --> sm{Straż miejska}\n    sm -- mandat --> ok([Sprawa zamknięta])\n    sm -- odmowa --> zazalenie([Zażalenie])\n```", 'render' => true],
            ],
        ],
        [
            'title' => 'Obrazki',
            'intro' => 'Obrazki pochodzą wyłącznie z biblioteki mediów wiki — nie da się wstawić obrazka z innej strony ani dodać go samodzielnie. Potrzebujesz nowego zrzutu ekranu lub zdjęcia? Wyślij plik (PNG, JPEG lub WebP) przez stronę kontaktu, a administrator doda go do biblioteki i poda nazwę.',
            'examples' => [
                ['label' => 'Obrazek z biblioteki', 'md' => "![Formularz ePUAP z zaznaczonym polem adresata](media:epuap-adresat.png)", 'render' => false,
                    'note' => 'Zadziała, gdy plik o tej nazwie jest w bibliotece mediów.'],
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
                ['label' => 'Karta jednostki (klucz z konfiguracji: sm/… lub police/…)', 'md' => "[[jednostka:sm/legnica]]", 'render' => true],
                ['label' => 'Doświadczenia z przesłuchań (lista miast z wyszukiwarką)', 'md' => "[[data:sm-hints]]", 'render' => false,
                    'note' => 'Długa lista — używana na stronie „Czy straż miejska wezwie mnie na przesłuchanie?”.'],
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

// ─── Jednostki (sluzby/*) — z konfiguracji sm.json / police.json, notatka jako strona wiki 'sluzby/<slug>' ────────

const UNIT_TYPES = ['sm' => 'Straż Miejska / Gminna', 'police' => 'Policja'];

/** @return array<string, \SM> klucz → jednostka dla typu (globalne $SM_ADDRESSES / $POLICE_ADDRESSES). */
function unitTable(string $type): array
{
    global $SM_ADDRESSES, $POLICE_ADDRESSES;
    return match ($type) { 'sm' => $SM_ADDRESSES, 'police' => $POLICE_ADDRESSES };
}

/**
 * Slug jednostki z jej nazwy (address[0]); dla Policji ze skrótu (KP/KPP…). Gdy pierwsza linia nie mówi, czyja to
 * jednostka (np. „I Oddział Terenowy”), dokleja drugą. Liczony raz i zamrażany w wiki_unit_slugs.
 */
function unitSlugBase(string $type, \SM $unit): string
{
    $name = $type === 'police' ? $unit->getShortName() : $unit->getName();
    if (!preg_match('/stra[żz]|polic|komend|komisariat|posterun/iu', $unit->getName()))
        $name .= ' ' . ($unit->address[1] ?? '');
    return slugify($name) ?: 'jednostka';
}

/**
 * Wszystkie jednostki pogrupowane po slugu. Wpis z `parent` (dopisywany przez tools/sm-parser.js) to gmina obsługiwana
 * przez inną jednostkę, nie osobna strona. Kilka kluczy z tym samym adresem to jedna jednostka (jeden slug).
 * Brakujące slugi są zamrażane przy pierwszym wywołaniu (nowa jednostka w konfiguracji).
 * @return array<string, array{slug:string,type:string,keys:list<string>,unit:\SM,served:list<string> obsługiwany teren (własne klucze + gminy z parent),emails:list<string>}>
 */
function units(): array
{
    static $units = null;
    if ($units !== null) return $units;

    $frozen = [];
    foreach (db()->query('SELECT unit_type, unit_key, slug FROM wiki_unit_slugs') as $row)
        $frozen[$row['unit_type']][$row['unit_key']] = $row['slug'];

    $db = db();
    $ownTransaction = !$db->inTransaction(); // pierwsze wywołanie zamraża ~800 slugów — jedna transakcja
    if ($ownTransaction) $db->beginTransaction();
    $units = [];
    foreach (array_keys(UNIT_TYPES) as $type) {
        // aktywne przed zlikwidowanymi: przy kolizji nazw czysty slug dostaje działająca jednostka
        $table = unitTable($type);
        uasort($table, fn($x, $y) => ($y->active ?? true) <=> ($x->active ?? true));
        $identity = []; // adres → slug: klucze jednego komisariatu (różne skrzynki dzielnicowych) to jedna strona
        $keySlug = [];
        foreach ($table as $key => $unit) {
            if ($key === '_nieznane' || isset($unit->parent) || empty($unit->address)) continue;
            $id = implode('|', $unit->address);
            $slug = $frozen[$type][$key] ?? null;
            if ($slug === null) {
                $slug = $identity[$id] ?? uniqueUnitSlug(unitSlugBase($type, $unit), $unit, $units);
                $slug = freezeUnitSlug($type, $key, $slug);
            }
            $identity[$id] = $keySlug[$key] = $slug;
            if (!isset($units[$slug]))
                $units[$slug] = ['slug' => $slug, 'type' => $type, 'keys' => [], 'unit' => $unit, 'served' => [], 'emails' => []];
            $units[$slug]['keys'][] = $key;
            $units[$slug]['served'][] = areaName($key, $unit);
            if ($unit->email && !in_array($unit->email, $units[$slug]['emails'], true)) $units[$slug]['emails'][] = $unit->email;
        }
        foreach ($table as $key => $unit) {
            $parentSlug = isset($unit->parent) ? ($keySlug[$unit->parent] ?? null) : null;
            if ($parentSlug) $units[$parentSlug]['served'][] = areaName($key, null);
        }
    }
    $collator = new \Collator('pl_PL'); // polska kolejność (Ł po L, Ż na końcu), strcoll zależy od locale procesu
    foreach ($units as &$entry) {
        $entry['served'] = array_values(array_unique(array_filter($entry['served'])));
        $collator->sort($entry['served']);
    }
    unset($entry);
    if ($ownTransaction) $db->commit();
    uasort($units, fn($a, $b) => $collator->compare($a['unit']->getName(), $b['unit']->getName()));
    return $units;
}

/**
 * Nazwa obsługiwanego terenu z klucza konfiguracji (klucze to gminy/miejscowości, w eksporcie zlowercase'owane).
 * `city` bierzemy tylko, gdy to ta sama nazwa — wpisy z `parent` dziedziczą `city` po jednostce (np. Bielany → Warszawa).
 */
function areaName(string $key, ?\SM $unit): ?string
{
    if (str_contains($key, '_')) return null; // wewnętrzny identyfikator (warszawa_ot5), nie nazwa terenu
    $key = preg_replace('/-miasto$/u', '', trim($key, ' ,.'));
    if ($unit && $unit->city && mb_strtolower($unit->city) === $key) return $unit->city;
    return mb_convert_case($key, MB_CASE_TITLE);
}

/** Slug wolny dla nowej jednostki: najpierw z nazwą miasta, potem z numerem. */
function uniqueUnitSlug(string $base, \SM $unit, array $units): string
{
    $taken = fn(string $slug) => isset($units[$slug]) || unitsBySlug($slug);
    if (!$taken($base)) return $base;
    $withCity = slugify($base . ' ' . ($unit->city ?? ''));
    if ($withCity !== $base && !$taken($withCity)) return $withCity;
    for ($i = 2; $taken("$base-$i"); $i++);
    return "$base-$i";
}

function unitBySlug(string $slug): ?array
{
    return units()[$slug] ?? null;
}

/**
 * Doświadczenia z przesłuchań: miasto → `hint` aktywnych SM (bez domyślnego „Masz doświadczenia…” i wpisów
 * „Miejscowość … obsługuje”). Blok [[data:sm-hints]] na /przesluchanie.html i fallback StaticPagesHandler::hearing.
 * @return array<string,string>
 */
function smHints(): array
{
    $hints = [];
    foreach (unitTable('sm') as $sm) {
        if (!$sm->active || !$sm->hint) continue;
        if (str_starts_with($sm->hint, 'Masz doświadczenia we współpracy') || str_starts_with($sm->hint, 'Miejscowość ')) continue;
        $hints[$sm->city] = $sm->hint;
    }
    $collator = new \Collator('pl_PL');
    uksort($hints, fn($a, $b) => $collator->compare($a, $b));
    return $hints;
}

/** Jednostka po kluczu konfiguracji ('sm/legnica', 'police/kobierzyce') — dla [[jednostka:…]]. */
function unitByKey(string $type, string $key): ?array
{
    $key = mb_strtolower($key);
    $table = unitTable($type);
    if (!isset($table[$key])) return null;
    if (isset($table[$key]->parent)) $key = $table[$key]->parent;
    foreach (units() as $entry)
        if ($entry['type'] === $type && in_array($key, $entry['keys'], true)) return $entry;
    return null;
}

function unitCard(array $entry): string
{
    return renderTemplate('wiki/_unit-card.html.twig', ['entry' => $entry, 'typeName' => UNIT_TYPES[$entry['type']]]);
}

// ─── Wyszukiwarka (/wiki/_search) ────────────────────────────────────────────────────────────────────────────────

/** Tekst do porównań bez ogonków i wielkości liter (jednostki szukane w konfiguracji, nie w FTS). */
function foldText(string $text): string
{
    return slugify($text) === '' ? '' : str_replace('-', ' ', slugify($text));
}

/**
 * Strony (FTS5) i jednostki (nazwa, miasto, obsługiwane gminy). Każde słowo zapytania musi pasować.
 * @return array{pages:list<array{url:string,title:string,snippet:string}>,units:list<array{url:string,title:string,meta:string}>}
 */
function search(string $query): array
{
    $pages = [];
    foreach (searchPages($query) as $row) {
        $pages[] = [
            'url' => $row['route_alias'] ? "/{$row['route_alias']}.html" : ($row['path'] === 'start' ? '/wiki' : "/wiki/{$row['path']}"),
            'title' => $row['title'],
            'snippet' => strtr(htmlspecialchars($row['snippet'], ENT_QUOTES), ["\u{E020}" => '<mark>', "\u{E021}" => '</mark>']),
        ];
    }

    $terms = array_filter(explode(' ', foldText($query)));
    $units = [];
    if ($terms) {
        foreach (units() as $entry) {
            $haystack = ' ' . foldText($entry['unit']->getName() . ' ' . ($entry['unit']->city ?? '') . ' ' . implode(' ', $entry['served'])) . ' ';
            foreach ($terms as $term)
                if (!str_contains($haystack, " $term")) continue 2; // początek słowa: „krzyk” znajduje „Krzyki”
            $units[] = [
                'url' => "/wiki/sluzby/{$entry['slug']}",
                'title' => $entry['unit']->getName(),
                'meta' => UNIT_TYPES[$entry['type']] . ($entry['unit']->city ? ' · ' . $entry['unit']->city : ''),
            ];
            if (count($units) >= 30) break;
        }
    }
    return ['pages' => $pages, 'units' => $units];
}
