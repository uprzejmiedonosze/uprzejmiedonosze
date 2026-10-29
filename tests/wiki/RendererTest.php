<?php

namespace UprzejmieDonosze\Tests\Wiki;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use wiki\Renderer;

class RendererTest extends TestCase
{
    private const MEDIA = [
        'ekran.png' => ['name' => 'ekran.png', 'b2_key' => 'wiki/ekran.png', 'width' => 800, 'height' => 600],
    ];

    private function renderer(array $embeds = []): Renderer
    {
        return new Renderer($embeds, fn(string $name) => self::MEDIA[$name] ?? null);
    }

    public function testMarkdownAndTables(): void
    {
        $r = $this->renderer()->render("**gruby** ~~skreślony~~\n\n| a | b |\n|---|---|\n| 1 | 2 |\n");
        $this->assertStringContainsString('<strong>gruby</strong>', $r->html);
        $this->assertStringContainsString('<del>skreślony</del>', $r->html);
        $this->assertStringContainsString('<table>', $r->html);
        $this->assertSame([], $r->errors);
    }

    public function testRawHtmlIsStripped(): void
    {
        $r = $this->renderer()->render("<script>alert(1)</script>\n\nTekst <img src=x onerror=alert(1)> dalej\n");
        $this->assertStringNotContainsString('<script', $r->html);
        $this->assertStringNotContainsString('onerror', $r->html);
        $this->assertStringContainsString('Tekst', $r->html);
    }

    public function testUnsafeLinksAreDropped(): void
    {
        $r = $this->renderer()->render('[klik](javascript:alert(1)) [dane](data:text/html,x)');
        $this->assertStringNotContainsString('javascript:', $r->html);
        $this->assertStringNotContainsString('data:text', $r->html);
    }

    public function testExternalLinksGetNofollow(): void
    {
        $r = $this->renderer()->render('[obcy](https://example.com/x) [nasz](https://' . HOST . '/faq.html) [lokalny](/faq.html)');
        $this->assertStringContainsString('<a rel="nofollow ugc noopener" href="https://example.com/x">', $r->html);
        $this->assertSame(1, substr_count($r->html, 'nofollow'));
    }

    public function testHeadingIdsAndToc(): void
    {
        $r = $this->renderer()->render("# Tytuł\n\n## Czy zostanę wezwana? {#swiadek}\n\n## Zażalenie na SM\n\n### Zażalenie na SM\n\n## Dodaj 3. zdjęcie {#3zdjęcie}\n");
        $this->assertStringContainsString('<h2 id="swiadek">', $r->html);
        $this->assertStringContainsString('<h2 id="zazalenie-na-sm">', $r->html);
        $this->assertStringContainsString('<h3 id="zazalenie-na-sm-2">', $r->html);
        $this->assertStringContainsString('<h2 id="3zdjęcie">', $r->html, 'existing faq.html anchors keep working');
        $this->assertSame(['swiadek', 'zazalenie-na-sm', 'zazalenie-na-sm-2', '3zdjęcie'], array_column($r->toc, 'id'));
        $this->assertSame('Czy zostanę wezwana?', $r->toc[0]['text']);
        $this->assertSame([], $r->errors);
    }

    public function testOnlyIdAttributeIsAllowed(): void
    {
        $r = $this->renderer()->render("## Nagłówek {#ok onclick=\"alert(1)\" .klasa style=\"x\"}\n");
        $this->assertStringContainsString('<h2 id="ok">', $r->html);
        $this->assertStringNotContainsString('onclick', $r->html);
        $this->assertStringNotContainsString('klasa', $r->html);
        $this->assertStringNotContainsString('style', $r->html);
    }

    #[DataProvider('badIds')]
    public function testInvalidOrReservedIdsAreRejected(string $id): void
    {
        $r = $this->renderer()->render("## Nagłówek {#$id}\n\nAkapit {#$id}\n");
        $this->assertStringNotContainsString("id=\"$id\"", $r->html);
        $this->assertNotEmpty($r->errors);
    }

    public static function badIds(): array
    {
        return [['main'], ['Duże'], ['a"b'], ['x_y']];
    }

    public function testMediaImages(): void
    {
        $r = $this->renderer()->render("![Zrzut](media:ekran.png)\n\n![Obcy](https://evil.example/pixel.gif)\n\n![Brak](media:nie-ma.png)\n");
        $this->assertStringContainsString('src="/wiki/ekran.png"', $r->html);
        $this->assertStringContainsString('width="800"', $r->html);
        $this->assertStringNotContainsString('evil.example', $r->html);
        $this->assertStringNotContainsString('nie-ma.png', $r->html);
        $this->assertSame(['ekran.png'], $r->media);
        $this->assertCount(2, $r->errors);
    }

    public function testNoteDirective(): void
    {
        $r = $this->renderer()->render(":::uwaga{title=\"Ważne\"}\nTreść **uwagi**\n:::\n\nPo dyrektywie\n");
        $this->assertStringContainsString('<aside class="wiki-note wiki-note--uwaga">', $r->html);
        $this->assertStringContainsString('<p class="wiki-note__title">Ważne</p>', $r->html);
        $this->assertStringContainsString('<strong>uwagi</strong>', $r->html);
        $this->assertMatchesRegularExpression('#</aside>\s*<p>Po dyrektywie</p>#', $r->html);
        $this->assertSame([], $r->errors);
    }

    public function testNestedDirectivesNeedDifferentFence(): void
    {
        $md = "::::howto{id=telefon title=Telefonicznie cta=tel:986 cta_label=\"Zadzwoń pod 986\"}\n"
            . "Kiedy stosować\n\n:::uwaga\nW środku\n:::\n\nNadal w karcie\n::::\n\nPoza kartą\n";
        $r = $this->renderer()->render($md);
        $this->assertSame([], $r->errors);
        $this->assertStringContainsString('<div class="howto" id="telefon">', $r->html);
        $this->assertStringContainsString('<a href="tel:986" class="button inline cta">Zadzwoń pod 986</a>', $r->html);
        $card = substr($r->html, 0, strpos($r->html, 'Poza kartą'));
        $this->assertStringContainsString('W środku', $card);
        $this->assertStringContainsString('Nadal w karcie', $card);
        $this->assertGreaterThan(strpos($r->html, '</aside>'), strpos($r->html, 'Nadal w karcie'));
    }

    public function testUnclosedDirectiveIsReported(): void
    {
        $r = $this->renderer()->render(":::uwaga\nA\n\nB\n");
        $this->assertContains(':::uwaga nie jest zamknięta (brak linii :::).', $r->errors);
    }

    public function testFenceInsideCodeBlockDoesNotCloseDirective(): void
    {
        $r = $this->renderer()->render(":::info\n```\n:::uwaga\nprzykład\n:::\n```\nPo kodzie\n:::\n\nPoza\n");
        $this->assertSame([], $r->errors);
        $this->assertStringContainsString(":::uwaga\nprzykład\n:::", $r->html);
        $this->assertMatchesRegularExpression('#Po kodzie</p>\s*</aside>\s*<p>Poza</p>#', $r->html);
    }

    public function testDirectiveAttributesAreValidated(): void
    {
        $r = $this->renderer()->render(":::howto{id=x title=T cta=\"javascript:alert(1)\" onclick=x}\nA\n:::\n");
        $this->assertStringNotContainsString('javascript:', $r->html);
        $this->assertStringNotContainsString('onclick', $r->html);
        $this->assertCount(2, $r->errors);

        $r = $this->renderer()->render(":::howto{title=T}\nA\n:::\n");
        $this->assertContains(':::howto wymaga atrybutu id.', $r->errors);
    }

    public function testUnknownDirectiveIsPlainText(): void
    {
        $r = $this->renderer()->render(":::../../base\nA\n:::\n");
        $this->assertStringNotContainsString('<aside', $r->html);
        $r = $this->renderer()->render(":::skrypt\nA\n:::\n");
        $this->assertStringContainsString(':::skrypt', $r->html);
        $this->assertContains('Nieznana dyrektywa :::skrypt.', $r->errors);
    }

    public function testEmbeds(): void
    {
        $embeds = ['data' => fn(string $arg) => $arg === 'sm-hints' ? '<div class="hints"></div>' : null];
        $r = $this->renderer($embeds)->render("[[toc]]\n\n## Pierwszy\n\n[[data:sm-hints]]\n\n[[data:nieznane]]\n\n[[jednostka:sm/legnica]]\n");
        $this->assertStringContainsString('<a href="#pierwszy">Pierwszy</a>', $r->html, 'toc sees headings below it');
        $this->assertStringContainsString('<div class="hints"></div>', $r->html);
        $this->assertContains('Nieznany blok [[data:nieznane]].', $r->errors);
        $this->assertContains('Blok [[jednostka]] nie jest dostępny na tej stronie.', $r->errors);
    }

    #[DataProvider('sexPlacements')]
    public function testSexTokens(string $md, string $expected): void
    {
        $r = $this->renderer()->render($md);
        $this->assertSame([], $r->errors);
        $female = $r->personalize(['anonimowa' => 'anonimowa', 'wezwana' => 'wezwana']);
        $male = $r->personalize(['anonimowa' => 'anonimowy', 'wezwana' => 'wezwany']);
        $this->assertStringContainsString(sprintf($expected, 'anonimowa'), $female->html);
        $this->assertStringContainsString(sprintf($expected, 'anonimowy'), $male->html);
    }

    public static function sexPlacements(): array
    {
        return [
            'end of heading' => ["## Czy będę {{sex:anonimowa}}\n", 'Czy będę %s</h2>'],
            'heading with id' => ["## Czy będę {{sex:anonimowa}} {#anonimowosc}\n", '<h2 id="anonimowosc">Czy będę %s</h2>'],
            'end of paragraph' => ["Jesteś {{sex:anonimowa}}\n", 'Jesteś %s</p>'],
            'list item' => ["- jesteś {{sex:anonimowa}}\n", '<li>jesteś %s</li>'],
            'link text' => ["[jesteś {{sex:anonimowa}}](/faq.html)\n", '>jesteś %s</a>'],
        ];
    }

    public function testSexTokenInTocAndUnknownToken(): void
    {
        $r = $this->renderer()->render("## Czy zostanę {{sex:wezwana}}?\n\n{{sex:nieistnieje}}\n");
        $this->assertSame('Czy zostanę wezwany?', $r->personalize(['wezwana' => 'wezwany'])->toc[0]['text']);
        $this->assertContains('Nieznana forma {{sex:nieistnieje}}.', $r->errors);
    }

    public function testSexValueIsEscaped(): void
    {
        $r = $this->renderer()->render("Jesteś {{sex:anonimowa}}\n")->personalize(['anonimowa' => '<b>x</b>']);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $r->html);
    }

    public function testBodySizeLimit(): void
    {
        $r = $this->renderer()->render(str_repeat('a', \wiki\MAX_BODY_BYTES + 1));
        $this->assertNotEmpty($r->errors);
    }

    public function testSlugify(): void
    {
        $this->assertSame('straz-miejska-w-legnicy', \wiki\slugify('Straż Miejska w Legnicy'));
        $this->assertSame('kp-wroclaw-krzyki', \wiki\slugify('KP Wrocław-Krzyki'));
        $this->assertSame('zazolc-gesla-jazn', \wiki\slugify('ZAŻÓŁĆ gęślą jaźń!'));
    }
}
