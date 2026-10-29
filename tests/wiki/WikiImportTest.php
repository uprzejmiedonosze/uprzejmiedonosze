<?php

namespace UprzejmieDonosze\Tests\Wiki;

use PHPUnit\Framework\TestCase;
use wiki\import\Converter;

require_once __DIR__ . '/../../export/inc/WikiImport.php';

class WikiImportTest extends TestCase
{
    private function convert(string $doku, string $namespace = 'niezbednik'): array
    {
        $links = [
            'niezbednik:wysylka_przez_epuap' => '/wiki/wysylka-epuap',
            'wzory_pism:start' => '/wiki/wzory',
            'faq' => '/faq.html',
        ];
        $titles = ['wzory_pism:start' => 'Wzory pism'];
        $converter = new Converter(fn($id) => $links[$id] ?? null, $namespace, fn($id) => $titles[$id] ?? null);
        return [$converter->convert($doku), $converter];
    }

    public function testCleanIdLikeDokuWiki(): void
    {
        $this->assertSame('niezbednik:wysylka_przez_epuap', \wiki\dokuCleanId('niezbednik:Wysyłka przez EPUAP'));
        $this->assertSame('pytania:zly-adres-na-mapie:zly_adres_na_mapie', \wiki\dokuCleanId('pytania:Zły-adres-na-mapie:Zły adres na mapie'));
        $this->assertSame('niezbednik/wysylka_przez_epuap', \wiki\legacyPath('niezbednik/Wysy%C5%82ka%20przez%20EPUAP'));
        $this->assertSame('epuap_1-min.png', \wiki\dokuMediaName(':niezbednik:epuap_1-min.png?direct&400'));
    }

    public function testTitleHeadingsAndText(): void
    {
        [$md, $c] = $this->convert("====== Wysyłka przez EPUAP ======\n\nTekst **gruby** i //kursywa// oraz https://example.com/a//b.\n\n===== Logowanie =====\n\n==== Pismo ====\n");
        $this->assertSame('Wysyłka przez EPUAP', $c->title);
        $this->assertStringNotContainsString('EPUAP ==', $md);
        $this->assertStringContainsString("## Logowanie", $md);
        $this->assertStringContainsString("### Pismo", $md);
        $this->assertStringContainsString('**gruby** i *kursywa* oraz https://example.com/a//b.', $md, 'URLs keep their double slashes');
    }

    public function testListsAndTables(): void
    {
        [$md] = $this->convert("Kroki:\n  - pierwszy\n  - drugi\n    * zagnieżdżony\n\n^ Kategoria ^ Przepis ^\n| Trawnik | art. 144 |\n");
        $this->assertStringContainsString("Kroki:\n\n1. pierwszy\n1. drugi\n    - zagnieżdżony", $md);
        $this->assertStringContainsString("| Kategoria | Przepis |\n|---|---|\n| Trawnik | art. 144 |", $md);
    }

    public function testLinksAndMedia(): void
    {
        [$md, $c] = $this->convert("[[wysylka_przez_epuap|ePUAP]] [[wzory_pism:start|]] [[:faq#recydywa|FAQ]] [[https://isap.sejm.gov.pl|ISAP]] [[nie:ma|zgubiony]]\n\n{{ :niezbednik:epuap_1-min.png?direct&400 |Formularz}} {{:plik.pdf|PDF}}");
        $this->assertStringContainsString('[ePUAP](/wiki/wysylka-epuap)', $md, 'relative id resolves in the page namespace');
        $this->assertStringContainsString('[Wzory pism](/wiki/wzory)', $md, 'empty label falls back to the target title');
        $this->assertStringContainsString('[FAQ](/faq.html#recydywa)', $md);
        $this->assertStringContainsString('[ISAP](https://isap.sejm.gov.pl)', $md);
        $this->assertStringContainsString(' zgubiony', $md);
        $this->assertStringContainsString('![Formularz](media:epuap_1-min.png)', $md);
        $this->assertSame(['epuap_1-min.png'], $c->media);
        $this->assertCount(2, $c->warnings, 'unknown page + non-image file');
    }

    public function testCodeBecomesLetterTemplate(): void
    {
        [$md] = $this->convert("Wzór:\n<code>\nKomenda Miejska\n\nZażalenie\n# punkt\n</code>\nDalej");
        $this->assertStringContainsString(":::wzor{title=\"Wzór pisma\"}\nKomenda Miejska\n\nZażalenie\n\\# punkt\n:::", $md);
        $this->assertSame([], \wiki\renderer()->render($md)->errors);
    }

    public function testFootnoteAndRule(): void
    {
        [$md] = $this->convert("Ustawa((Dz. U. 2024 poz. 1251)) obowiązuje.\n----\nKoniec");
        $this->assertStringContainsString('Ustawa (Dz. U. 2024 poz. 1251) obowiązuje.', $md);
        $this->assertStringContainsString("\n---\n", $md);
    }

    public function testUnitNoteKeepsOnlyNewInformation(): void
    {
        $unit = new \SM(json_encode([
            'address' => ['Straż Miejska w Testowie', 'ul. Prosta 1', '00-001 Testowo'],
            'email' => 'sm@testowo.pl', 'city' => 'Testowo',
            'hint' => \wiki\import\DEFAULT_HINT_PREFIX . ' Podziel się.',
        ]));
        $page = "====== Straż Miejska w Testowie ======\n\nul. Prosta 1\n\n00-001 Testowo\n\nAdres e-mail: sm@testowo.pl\n\nKod pocztowy: 00-001\n\nWojewództwo: Mazowieckie\n";
        $this->assertSame('', \wiki\import\unitNote($page, $unit), 'only config fields');
        $this->assertSame('Telefon dyżurny 986, nie wzywają na przesłuchanie.',
            \wiki\import\unitNote($page . "\nTelefon dyżurny 986, nie wzywają na przesłuchanie.\n", $unit));
        $this->assertSame('', \wiki\import\unitNote($page . "\nGmina Sędławki jest obsługiwana przez tę straż.\n", $unit), 'served area is on the card');

        $unit->hint = 'Wzywają na przesłuchanie przy każdym zgłoszeniu.';
        $this->assertSame('', \wiki\import\unitNote($page . "\nNiestety SM Testowo wzywa na przesłuchanie do każdego zgłoszenia.\n", $unit),
            'config hint is the summary of the old page');
        $this->assertNotSame('', \wiki\import\unitNote($page . "\nDużo więcej treści niż hint, dojazd, parking i telefon.\n", $unit, 'sm', ['wrocław']));
    }
}
