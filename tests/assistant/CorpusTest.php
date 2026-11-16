<?php

namespace UprzejmieDonosze\Tests\Assistant;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../export/inc/assistant/Corpus.php';

use function assistant\corpus\{acts, article, basics, knowledge, legal, search, setRoot, skills, splitSections, tokens};

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
        $this->assertSame(['prd', 'pusty'], array_column(acts(), 'id'));
    }

    public function testLegalChunksByArticleAndLookup(): void
    {
        $this->assertSame(['49', '92a'], array_column(legal('prd'), 'key'));
        $this->assertSame([], legal('pusty'));
        $this->assertStringContainsString('chodniku', article('prd', 'Art. 49')['text']);
        $this->assertSame('Art. 92a', article('prd', '92A')['title']);
        $this->assertNull(article('prd', '1'));
        $this->assertNull(article('prd', 'brak liczby'));
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
