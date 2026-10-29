<?PHP

require_once(__DIR__ . '/AbstractHandler.php');
require_once(__DIR__ . '/../Wiki.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * Wiki w UD: /wiki/<ścieżka> (widok), /wiki/_edit|_history|_rev|_revert|_recent|_preview (trasy systemowe).
 * Logika (uprawnienia, walidacja, zapis) w src/inc/Wiki.php — handler tylko parsuje request i renderuje.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class WikiHandler extends AbstractHandler {

    /** URL strony: 'start' to /wiki. */
    public static function url(string $path): string {
        return $path === 'start' ? '/wiki' : "/wiki/$path";
    }

    private static function user(Request $request): ?\user\User {
        return $request->getAttribute('user', null);
    }

    private static function sex(Request $request): array {
        $user = self::user($request);
        return $user ? $user->getSex() : SEXSTRINGS['?'];
    }

    private static function wikiParams(Request $request, string $path): array {
        $user = self::user($request);
        return [
            'path' => $path,
            'url' => self::url($path),
            'canEdit' => \wiki\canEdit($user),
            'canModerate' => \wiki\canModerate($user),
            'blockedReason' => \wiki\editBlockedReason($user),
        ];
    }

    public function page(Request $request, Response $response, array $args): Response {
        $path = $args['path'] ?? 'start';
        if (isset($args['path']) && $path === 'start')
            return AbstractHandler::redirect('/wiki', 301);

        $page = \wiki\isValidPath($path) ? \wiki\getPage($path) : null;
        if (!$page) {
            $target = \wiki\redirectTarget($path);
            if ($target)
                return AbstractHandler::redirect(self::url($target), 301);
            if (!\wiki\isValidPath($path) || !\wiki\canEdit(self::user($request)))
                throw new HttpNotFoundException($request);
            return AbstractHandler::renderHtml($request, $response, 'wiki-missing', [
                'wiki' => self::wikiParams($request, $path),
            ])->withStatus(404);
        }

        $result = \wiki\renderForUser($page['body_md'], self::sex($request));
        return AbstractHandler::renderHtml($request, $response, 'wiki', [
            'wiki' => self::wikiParams($request, $path) + [
                'page' => $page,
                'content' => $result->html,
                'toc' => $result->toc,
            ],
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response {
        $path = $args['path'] ?? 'start';
        if (!\wiki\isValidPath($path))
            throw new HttpNotFoundException($request);
        $page = \wiki\getPage($path);
        return $this->renderEditor($request, $response, $path, [
            'title' => $page['title'] ?? '',
            'body' => $page['body_md'] ?? '',
            'summary' => '',
            'baseRev' => $page['current_rev'] ?? '',
            'isNew' => !$page,
        ]);
    }

    public function save(Request $request, Response $response, array $args): Response {
        $path = $args['path'] ?? 'start';
        $params = (array)$request->getParsedBody();
        $form = [
            'title' => (string)($params['title'] ?? ''),
            'body' => (string)($params['body'] ?? ''),
            'summary' => (string)($params['summary'] ?? ''),
            'baseRev' => (string)($params['baseRev'] ?? ''),
        ];
        try {
            \wiki\save(self::user($request), $path, $form['title'], $form['body'], $form['summary'],
                $form['baseRev'] === '' ? null : (int)$form['baseRev']);
        } catch (ValidationException $e) {
            return $this->renderEditor($request, $response, $path, $form + [
                'isNew' => !\wiki\getPage($path),
                'error' => ['field' => $e->getField(), 'message' => $e->getMessage()],
            ])->withStatus(422);
        }
        return AbstractHandler::redirect(self::url($path));
    }

    private function renderEditor(Request $request, Response $response, string $path, array $form): Response {
        $sex = self::sex($request);
        $guide = array_map(fn($section) => ['examples' => array_map(
            fn($example) => $example + ['html' => $example['render'] ? \wiki\renderForUser($example['md'], $sex)->html : null],
            $section['examples'])] + $section, \wiki\guide());
        return AbstractHandler::renderHtml($request, $response, 'wiki-edit', [
            'wiki' => self::wikiParams($request, $path) + [
                'form' => $form + ['error' => null],
                'guide' => $guide,
                'guideRules' => \wiki\GUIDE_RULES,
                // formy rodzajowe do ściągi w poradniku (bez nazw odznak, które też są w SEXSTRINGS)
                'sexKeys' => array_values(array_filter(array_keys(SEXSTRINGS['?']), fn($k) => preg_match('/^\p{Ll}+$/u', $k))),
            ],
        ]);
    }

    /** Podgląd na żywo w edytorze: ten sam renderer, błędy zamiast odrzucenia. */
    public function preview(Request $request, Response $response): Response {
        $params = (array)$request->getParsedBody();
        $result = \wiki\renderForUser((string)($params['body'] ?? ''), self::sex($request));
        return AbstractHandler::renderJson($response, [
            'html' => $result->html,
            'errors' => $result->errors,
            'toc' => $result->toc,
        ]);
    }

    public function history(Request $request, Response $response, array $args): Response {
        $path = $args['path'];
        $page = \wiki\isValidPath($path) ? \wiki\getPage($path) : null;
        if (!$page)
            throw new HttpNotFoundException($request);
        return AbstractHandler::renderHtml($request, $response, 'wiki-history', [
            'wiki' => self::wikiParams($request, $path) + [
                'page' => $page,
                'revisions' => \wiki\revisions((int)$page['id']),
            ],
        ]);
    }

    public function revision(Request $request, Response $response, array $args): Response {
        $rev = \wiki\getRevision((int)$args['revId']);
        $path = $rev ? \wiki\pathById((int)$rev['page_id']) : null;
        if (!$rev || !$path || !\wiki\getPage($path))
            throw new HttpNotFoundException($request);
        $previous = \wiki\previousRevision((int)$rev['page_id'], (int)$rev['id']);
        $page = \wiki\getPage($path);
        return AbstractHandler::renderHtml($request, $response, 'wiki-revision', [
            'wiki' => self::wikiParams($request, $path) + [
                'page' => $page,
                'rev' => $rev,
                'isCurrent' => (int)$page['current_rev'] === (int)$rev['id'],
                'previous' => $previous,
                'diff' => \wiki\diffLines($previous['body_md'] ?? '', $rev['body_md']),
                'content' => \wiki\renderForUser($rev['body_md'], self::sex($request))->html,
            ],
        ]);
    }

    public function revert(Request $request, Response $response, array $args): Response {
        $path = \wiki\revert(self::user($request), (int)$args['revId']);
        return AbstractHandler::redirect(self::url($path));
    }

    public function recent(Request $request, Response $response): Response {
        return AbstractHandler::renderHtml($request, $response, 'wiki-recent', [
            'wiki' => self::wikiParams($request, '_recent') + [
                'changes' => \wiki\recentChanges(100),
            ],
        ]);
    }
}
