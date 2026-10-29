<?php

namespace UprzejmieDonosze\Tests\Wiki;

use user\User;
use UprzejmieDonosze\Tests\WikiTestCase;

class WikiDomainTest extends WikiTestCase
{
    private function editor(string $email = 'wiki-editor@example.com'): User
    {
        $user = new User();
        $user->data->email = $email;
        $user->data->name = 'Ala Kowalska';
        $user->data->address = 'ul. Testowa 1, Wrocław';
        $user->appsCount = 3;
        return $user;
    }

    public function testEditPermissions(): void
    {
        $this->assertSame('login', \wiki\editBlockedReason(null));

        $user = $this->editor();
        $this->assertNull(\wiki\editBlockedReason($user));
        $this->assertTrue(\wiki\canEdit($user));

        $user->appsCount = 0;
        $this->assertSame('apps', \wiki\editBlockedReason($user), 'edits need at least one sent report');

        $user = $this->editor();
        $user->data->wikiBlocked = true;
        $this->assertSame('blocked', \wiki\editBlockedReason($user));

        $user = $this->editor();
        $user->data->address = '';
        $this->assertSame('register', \wiki\editBlockedReason($user));

        $this->assertFalse(\wiki\canModerate($this->editor()));
    }

    public function testPathValidation(): void
    {
        foreach (['start', 'zazalenie-na-decyzje-sm', 'prawo/trawnik-art-144', 'sluzby/straz-miejska-w-legnicy'] as $path)
            $this->assertTrue(\wiki\isValidPath($path), $path);
        foreach (['', '_edit', 'Duże', 'a//b', 'a/', '../x', 'zażalenie', 'a/b/c/d/e'] as $path)
            $this->assertFalse(\wiki\isValidPath($path), $path);
    }

    public function testSaveCreatesAndUpdates(): void
    {
        $user = $this->editor();
        $rev = \wiki\save($user, 'test-domena', '  Tytuł  ', "## Sekcja\r\nTreść", 'nowa', null);
        $page = \wiki\getPage('test-domena');
        $this->assertSame('Tytuł', $page['title']);
        $this->assertSame("## Sekcja\nTreść", $page['body_md']);
        $this->assertSame('Ala', \wiki\getRevision($rev)['author_name'], 'only the first name is shown in history');

        $rev2 = \wiki\save($user, 'test-domena', 'Tytuł', "## Sekcja\nNowa treść", '', $rev);
        $this->assertGreaterThan($rev, $rev2);

        $this->assertSame($rev2, \wiki\save($user, 'test-domena', 'Tytuł', "## Sekcja\nNowa treść", '', $rev2),
            'unchanged content does not create a revision');
        $this->assertSame('prawo', \wiki\sectionOf('prawo/trawnik'));
        $this->assertSame('', \wiki\sectionOf('prawo'));
    }

    public function testSaveRejectsConflict(): void
    {
        $user = $this->editor();
        $rev = \wiki\save($user, 'test-konflikt', 'T', 'pierwsza', '', null);
        \wiki\save($this->editor('other@example.com'), 'test-konflikt', 'T', 'druga', '', $rev);

        try {
            \wiki\save($user, 'test-konflikt', 'T', 'moja', '', $rev);
            $this->fail('stale baseRev must be rejected');
        } catch (\ValidationException $e) {
            $this->assertSame('baseRev', $e->getField());
        }
        $this->assertSame('druga', \wiki\getPage('test-konflikt')['body_md']);
    }

    public function testSaveRejectsInvalidContent(): void
    {
        $user = $this->editor();
        $cases = [
            'title' => ['test-x', '', 'treść'],
            'path' => ['Zła Ścieżka', 'T', 'treść'],
            'body' => ['test-x', 'T', ":::nieznana\nx\n:::"],
        ];
        foreach ($cases as $field => [$path, $title, $body]) {
            try {
                \wiki\save($user, $path, $title, $body, '', null);
                $this->fail("$field must be rejected");
            } catch (\ValidationException $e) {
                $this->assertSame($field, $e->getField());
            }
        }
        $this->assertNull(\wiki\getPage('test-x'));
    }

    public function testSaveRequiresPermission(): void
    {
        $user = $this->editor();
        $user->appsCount = 0;
        $this->expectException(\ForbiddenException::class);
        \wiki\save($user, 'test-y', 'T', 'x', '', null);
    }

    public function testRevertRequiresModerator(): void
    {
        $rev = \wiki\save($this->editor(), 'test-revert', 'T', 'x', '', null);
        $this->expectException(\ForbiddenException::class);
        \wiki\revert($this->editor(), $rev);
    }

    public function testDiffLines(): void
    {
        $diff = \wiki\diffLines("a\nb\nc", "a\nB\nc\nd");
        $this->assertSame(
            [['=', 'a'], ['-', 'b'], ['+', 'B'], ['=', 'c'], ['+', 'd']],
            array_map(fn($op) => [$op['op'], $op['line']], $diff)
        );
    }

    public function testDiffTrimsCommonLinesAndCapsSize(): void
    {
        $lines = implode("\n", range(1, 5000));
        $diff = \wiki\diffLines($lines, str_replace("\n2500\n", "\nzmiana\n", $lines));
        $this->assertCount(5001, $diff);
        $this->assertSame([['-', '2500'], ['+', 'zmiana']], array_map(fn($op) => [$op['op'], $op['line']],
            array_values(array_filter($diff, fn($op) => $op['op'] !== '='))));

        $this->assertNull(\wiki\diffLines(implode("\n", range(1, 2000)), implode("\n", range(3000, 5000))));
    }

    public function testSearchPages(): void
    {
        \wiki\savePage('test-szukaj', 'Zażalenie na straż', "## Termin\n\nZażalenie składasz w ciągu 7 dni. <b>x</b>\n\n:::howto{id=a title=\"Karta ePUAP\"}\nTreść\n:::", '', null, null);
        \wiki\savePage('test-inna', 'Inna strona', 'Bez związku.', '', null, null);

        foreach (['zazalenie', 'ZAŻALENIE', 'zażal', 'termin 7', 'epuap'] as $query)
            $this->assertContains('/wiki/test-szukaj', array_column(\wiki\search($query)['pages'], 'url'), $query);
        $this->assertSame([], \wiki\search('howto')['pages'], 'directive syntax is not indexed');
        $this->assertSame([], \wiki\search('zażalenie nieistniejąceslowo')['pages'], 'every word must match');
        $this->assertSame([], \wiki\search('" OR * NEAR(')['pages'], 'FTS syntax in the query is harmless');

        $snippet = \wiki\search('składasz')['pages'][0]['snippet'];
        $this->assertStringContainsString('<mark>składasz</mark>', $snippet);
        $this->assertStringNotContainsString('<b>', $snippet);

        \wiki\deletePage('test-szukaj');
        $this->assertSame([], \wiki\search('zażalenie składasz')['pages']);
    }

    public function testSearchUnits(): void
    {
        $units = \wiki\search('krzyki')['units'];
        $this->assertContains('/wiki/sluzby/kp-wroclaw-krzyki', array_column($units, 'url'));
        $this->assertContains('/wiki/sluzby/kp-wroclaw-krzyki', array_column(\wiki\search('kobierzyce')['units'], 'url'), 'served municipalities are searchable');
        $this->assertContains('/wiki/sluzby/straz-miejska-w-legnicy', array_column(\wiki\search('straz legnica')['units'], 'url'));
    }

    public function testGuideExamplesRenderWithoutErrors(): void
    {
        foreach (\wiki\guide() as $section) {
            $this->assertNotEmpty($section['intro'], $section['title']);
            foreach ($section['examples'] as $example) {
                $result = \wiki\renderer()->render($example['md']);
                $label = "{$section['title']} / {$example['label']}";
                if ($example['render']) {
                    $this->assertSame([], $result->errors, $label);
                    $this->assertNotSame('', trim($result->html), $label);
                    continue;
                }
                // poza kontekstem dozwolone są tylko błędy „brak pliku”/„blok niedostępny”, nie literówki w składni
                foreach ($result->errors as $error)
                    $this->assertMatchesRegularExpression('/bibliotece mediów|nie jest dostępny/', $error, $label);
            }
        }
    }
}
