<?php

namespace UprzejmieDonosze\Tests\Wiki;

use UprzejmieDonosze\Tests\WikiTestCase;

class WikiStoreTest extends WikiTestCase
{
    public function testSchemaIsAppliedOnce(): void
    {
        $this->assertSame(1, (int)\wiki\db()->query('PRAGMA user_version')->fetchColumn());
        $tables = \wiki\db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'wiki_%'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['wiki_pages', 'wiki_revisions', 'wiki_unit_slugs', 'wiki_redirects'] as $table)
            $this->assertContains($table, $tables);
    }

    public function testSavePageCreatesPageAndRevisions(): void
    {
        $rev1 = \wiki\savePage('test/strona', 'Tytuł', 'treść 1', 'pierwsza', 'a@example.com', 'Ala', 'poradniki');
        $rev2 = \wiki\savePage('test/strona', 'Tytuł 2', 'treść 2', 'druga', 'b@example.com', 'Bob');

        $page = \wiki\getPage('test/strona');
        $this->assertSame('Tytuł 2', $page['title']);
        $this->assertSame('treść 2', $page['body_md']);
        $this->assertSame('poradniki', $page['section'], 'section is set on creation only');
        $this->assertSame($rev2, (int)$page['current_rev']);
        $this->assertSame('b@example.com', $page['updated_by']);

        $revisions = \wiki\revisions((int)$page['id']);
        $this->assertSame([$rev2, $rev1], array_map(fn($r) => (int)$r['id'], $revisions));
        $this->assertSame('treść 1', \wiki\getRevision($rev1)['body_md']);
    }

    public function testRouteAliasAndDelete(): void
    {
        \wiki\savePage('faq-test', 'FAQ', 'x', '', null, null, '', 'faq-test-alias');
        $this->assertSame('faq-test', \wiki\getPageByAlias('faq-test-alias')['path']);

        \wiki\deletePage('faq-test');
        $this->assertNull(\wiki\getPage('faq-test'));
        $this->assertNull(\wiki\getPageByAlias('faq-test-alias'));
        $this->assertNotContains('faq-test', array_column(\wiki\recentChanges(), 'path'));
    }

    public function testRedirectsStaySingleHop(): void
    {
        \wiki\addRedirect('niezbednik/a', 'a');
        \wiki\addRedirect('a', 'b');
        $this->assertSame('b', \wiki\redirectTarget('niezbednik/a'));
        $this->assertSame('b', \wiki\redirectTarget('a'));

        // back to the original path: the self-redirect disappears instead of looping
        \wiki\addRedirect('b', 'a');
        $this->assertNull(\wiki\redirectTarget('a'));
        $this->assertSame('a', \wiki\redirectTarget('b'));
    }

    public function testUnitSlugIsFrozen(): void
    {
        $this->assertSame('straz-miejska-w-legnicy', \wiki\freezeUnitSlug('sm', 'legnica', 'straz-miejska-w-legnicy'));
        $this->assertSame('straz-miejska-w-legnicy', \wiki\freezeUnitSlug('sm', 'legnica', 'inna-nazwa'));

        \wiki\freezeUnitSlug('police', 'Głuszyca', 'kp-gluszyca');
        \wiki\freezeUnitSlug('police', 'Jedlina-Zdrój', 'kp-gluszyca');
        $this->assertCount(2, \wiki\unitsBySlug('kp-gluszyca'));
    }

    public function testAnonymizeAuthorKeepsContent(): void
    {
        \wiki\savePage('test/anon', 'T', 'treść', '', 'gone@example.com', 'Gone');

        $this->assertSame(1, \wiki\anonymizeAuthor('gone@example.com'));

        $page = \wiki\getPage('test/anon');
        $this->assertSame('treść', $page['body_md']);
        $this->assertNull($page['updated_by']);
        $rev = \wiki\getRevision((int)$page['current_rev']);
        $this->assertNull($rev['author_email']);
        $this->assertSame(\wiki\DELETED_AUTHOR, $rev['author_name']);
    }
}
