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
            $media = $this->resolveMedia($image->getUrl(), 'obrazek');
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
                if ($media = $this->resolveMedia("media:$value", 'obrazek')) $valid[$key] = mediaUrl($media);
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
