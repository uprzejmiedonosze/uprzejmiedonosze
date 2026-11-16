<?PHP

require_once(__DIR__ . '/AbstractHandler.php');
require_once(__DIR__ . '/../assistant/Assistant.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Czat z asystentem AI (widżet na każdej stronie dla zalogowanych). Cienki handler — logika w src/inc/assistant/.
 * Jedna ciągła rozmowa na użytkownika; ta sama skrzynka przyjmuje powiadomienia systemowe (\chat\notify()).
 */
class ChatHandler extends AbstractHandler {
    private const PAGE = 50;

    /** GET /api/chat[?before=ID] — historia (od najstarszej). Pierwsza strona oznacza powiadomienia jako przeczytane. */
    public function history(Request $request, Response $response, array $args): Response {
        $email = $request->getAttribute('user')->getEmail();
        $before = $request->getQueryParams()['before'] ?? null;
        $messages = \chat\history($email, self::PAGE, $before !== null ? (int)$before : null);
        if ($before === null) \chat\markRead($email);
        return $this->renderJson($response, [
            'messages' => array_map('assistant\present', $messages),
            'hasMore' => count($messages) === self::PAGE,
        ]);
    }

    /**
     * GET /api/chat/unread — plakietka na przycisku czatu (tanie zapytanie przy każdym ładowaniu strony);
     * `canAttach` = użytkownik jest patronem i może wysyłać załączniki.
     */
    public function unread(Request $request, Response $response, array $args): Response {
        $user = $request->getAttribute('user');
        return $this->renderJson($response, [
            'unread' => \chat\unread($user->getEmail()),
            'canAttach' => \assistant\attachments\isAllowed($user),
        ]);
    }

    /**
     * POST /api/chat {message, attachments?: [{name, data}]} — pytanie do asystenta; odpowiedź synchronicznie.
     * Załączniki (JPEG/PNG/PDF) tylko dla patronów; obrazy zużywają kredyty zdjęć (402 przy wyczerpaniu).
     */
    public function send(Request $request, Response $response, array $args): Response {
        $body = $request->getParsedBody() ?? [];
        $attachments = $body['attachments'] ?? [];
        // Sama wiadomość jest wymagana tylko bez załączników (z plikami wystarczy plik).
        $text = $attachments ? (string)($body['message'] ?? '') : (string)$this->getParam($body, 'message');
        try {
            $reply = \assistant\reply($request->getAttribute('user'), $text, is_array($attachments) ? $attachments : [$attachments]);
        } catch (\assistant\ChatException $e) {
            throw new \Exception($e->getMessage(), $e->getCode(), $e);
        }
        return $this->renderJson($response, ['message' => $reply]);
    }

    /** DELETE /api/chat — „Nowa rozmowa”: kasuje zapisaną rozmowę (też powiadomienia). */
    public function clear(Request $request, Response $response, array $args): Response {
        return $this->renderJson($response, ['deleted' => \chat\removeAllForEmail($request->getAttribute('user')->getEmail())]);
    }
}
