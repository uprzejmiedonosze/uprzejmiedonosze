<?php

namespace UprzejmieDonosze\Tests\Assistant;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../export/inc/assistant/Corpus.php';

use function assistant\corpus\{acts, article, basics, knowledge, legal, legalSearchChunks, parts, provision, search, setRoot, skills, splitSections, tokens};

class CorpusTest extends TestCase
{
    protected function setUp(): void
    {
        setRoot(__DIR__ . '/../fixtures/assistant');
    }

    protected function tearDown(): void
    {
        setRoot(null);
    }

    public function testSplitSectionsByLevelTwoHeadings(): void
    {
        $s = splitSections("# Tytuł\nwstęp\n\n## A\nciało a\n### pod\nnadal a\n\n## B\nciało b\n");
        $this->assertSame(['Tytuł', 'A', 'B'], array_column($s, 'title'));
        $this->assertStringContainsString('### pod', $s[1]['text']);
    }

    public function testTokensFoldDiacriticsAndDropStopwords(): void
    {
        $this->assertSame(tokens('Czy będę ANONIMOWY?'), tokens('czy bede anonimowy'));
        $this->assertNotContains('czy', tokens('czy będę anonimowy'));
    }

    public function testSearchToleratesPolishInflection(): void
    {
        $hits = search(knowledge(), 'aplikacji mobilnej');
        $this->assertSame('Jak działa aplikacja mobilna?', $hits[0]['title']);
    }

    public function testSearchReturnsNothingWithoutMatch(): void
    {
        $this->assertSame([], search(knowledge(), 'kwantowa grawitacja'));
        $this->assertSame([], search(knowledge(), ''));
    }

    public function testBasicsIsReadAsWhole(): void
    {
        $this->assertStringContainsString('bezpłatny', basics());
    }

    public function testActsRejectUnsafeIds(): void
    {
        $this->assertSame(['prd', 'pusty', 'rozp', 'tar'], array_column(acts(), 'id'));
    }

    public function testLegalChunksByArticleAndLookup(): void
    {
        $this->assertSame(['49', '92a', '60', '60^1', '2', '130a'], array_column(legal('prd'), 'key')); // rozdział pominięty
        $this->assertSame([], legal('pusty'));
        $this->assertStringContainsString('chodniku', article('prd', 'Art. 49')['text']);
        $this->assertSame('Art. 92a', article('prd', '92A')['title']);
        $this->assertNull(article('prd', '1'));
        $this->assertNull(article('prd', 'brak liczby'));
        foreach (['60¹', 'Art. 60^1', '60[1]', '60(1)'] as $n)
            $this->assertSame('Art. 60¹', article('prd', $n)['title'], $n);
        $this->assertSame('Art. 60', article('prd', 'art. 60')['title']);
    }

    public function testSplitKeepsMultibyteLetters(): void
    {
        // „ą” = C4 85; bez /u \R traktuje 0x85 jako NEL i rozcina literę na dwie linie.
        $sections = splitSections("## Art. 1\nZatrzymanie pojazdu następuje, gdy wjeżdżają.\n");
        $this->assertSame('Zatrzymanie pojazdu następuje, gdy wjeżdżają.', $sections[0]['text']);
    }

    public function testLongArticlesSplitIntoPartsWithIntro(): void
    {
        $parts = parts(article('prd', '2'));
        $this->assertSame(['pkt 1', 'pkt 9', 'pkt 30'], array_column($parts, 'part'));
        $this->assertSame('Art. 2 pkt 9', $parts[1]['title']);
        $this->assertStringStartsWith("Użyte w ustawie określenia oznaczają:\n9) chodnik", $parts[1]['text']);
        $this->assertSame([], parts(article('prd', '49')), 'jeden ustęp = brak części');

        $ust = parts(article('prd', '130a'));
        $this->assertSame(['ust. 1', 'ust. 2'], array_column($ust, 'part'));
        $this->assertStringContainsString('2) braku tablic', $ust[0]['text'], 'punkty zostają w swoim ustępie');

        $titles = array_column(legalSearchChunks('prd', 10), 'title');
        $this->assertContains('Art. 2 pkt 30', $titles);
        $this->assertNotContains('Art. 2', $titles, 'długi artykuł zastąpiony częściami');
        $this->assertContains('Art. 2', array_column(legalSearchChunks('prd'), 'title'), 'krótki zostaje w całości');
        $this->assertSame('Art. 2 pkt 30', search(legalSearchChunks('prd', 10), 'unieruchomienie dłuższe niż minuta')[0]['title']);
    }

    public function testProvisionResolvesPartReferences(): void
    {
        $this->assertSame('Art. 2 pkt 30', provision('prd', 'art. 2 pkt 30')['title']);
        $this->assertSame('Art. 130a ust. 2', provision('prd', '130a ust. 2')['title']);
        $this->assertSame('Art. 130a', provision('prd', 'Art. 130a')['title']);
        $this->assertSame('Art. 60¹', provision('prd', '60¹')['title']);
        $this->assertSame('§ 2a', provision('rozp', '§ 2a')['title']);
        $this->assertNull(provision('prd', '2 pkt 999'));
        $this->assertNull(provision('prd', '999'));
    }

    public function testTableEntriesByLp(): void
    {
        $this->assertSame(['166', '101a'], array_column(legal('tar'), 'key')); // nagłówek części pominięty
        $this->assertSame('Lp. 101a', provision('tar', 'Lp. 101a')['title']);
        $this->assertSame('Lp. 166', provision('tar', 'lp 166')['title']);
        $this->assertSame('Lp. 166', search(legal('tar'), 'zatrzymywanie na przejściu dla pieszych')[0]['title']);
    }

    public function testRegulationChunksByParagraph(): void
    {
        $this->assertSame(['1', '2a'], array_column(legal('rozp'), 'key')); // rozdział pominięty
        $this->assertSame('§ 2a', article('rozp', '§ 2a')['title']);
        $this->assertStringContainsString('komendant', article('rozp', '2A')['text']);
        $this->assertSame('§ 2a', search(legal(), 'upoważnienie wydaje komendant')[0]['title']);
    }

    public function testLegalSearchFindsArticleByContent(): void
    {
        $hits = search(legal(), 'zatrzymanie pojazdu na chodniku');
        $this->assertSame('Art. 49', $hits[0]['title']);
    }

    public function testSkillsRequireFrontMatter(): void
    {
        $this->assertSame(['odwolanie'], array_keys(skills()));
        $this->assertStringContainsString('search_legal', skills()['odwolanie']['body']);
    }
}
