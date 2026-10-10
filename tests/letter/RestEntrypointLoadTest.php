<?php

namespace UprzejmieDonosze\Tests\Letter;

use PHPUnit\Framework\TestCase;

/**
 * Regresja: src/api/rest/index.php ładuje pliki w ustalonej kolejności. LetterProposal.php (a przez LetterVision.php także
 * integrations/Vision.php) wołane przez require_once, a późniejszy zwykły `require` Vision.php dawał na stagingu
 * „Cannot redeclare vision\decodeCandidatePhotos()”. Testy jednostkowe nie ładują index.php, więc sprawdzamy to w osobnym procesie PHP.
 */
class RestEntrypointLoadTest extends TestCase
{
    private const INDEX = __DIR__ . '/../../src/api/rest/index.php';

    public function testEveryIncludeOfAnIntegrationFileInTheEntrypointIsRequireOnce(): void
    {
        $src = file_get_contents(self::INDEX);
        preg_match_all("/^\s*(require_once|require|include_once|include)\s*\(\s*INC_DIR\s*\.\s*'\/(LetterProposal\.php|integrations\/[A-Za-z]+\.php)'/m", $src, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m, 'nie znaleziono includów integracji w index.php');
        foreach ($m as $inc) {
            $this->assertSame('require_once', $inc[1], "{$inc[2]} musi być ładowany przez require_once (inne pliki ładują go przez require_once)");
        }
    }

    public function testLoadOrderOfTheEntrypointDoesNotRedeclareFunctions(): void
    {
        // dokładnie te same includy co index.php, w tej samej kolejności, w czystym procesie
        $src = file_get_contents(self::INDEX);
        preg_match_all("/^\s*(?:require_once|require)\s*\(\s*INC_DIR\s*\.\s*'(\/(?:LetterProposal|integrations\/Vision)\.php)'/m", $src, $m);
        $this->assertCount(2, $m[1]);
        $inc = realpath(__DIR__ . '/../../src/inc');
        $code = "define('OPENAI_VISION_DETAIL','high'); require " . var_export(__DIR__ . '/../../vendor/autoload.php', true) . ';';
        foreach ($m[1] as $rel) $code .= ' require_once ' . var_export($inc . $rel, true) . ';';
        $code .= " echo 'LOADED';";

        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertStringContainsString('LOADED', implode("\n", $out));
    }
}
