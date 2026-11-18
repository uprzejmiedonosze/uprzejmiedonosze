<?PHP

require_once(__DIR__ . '/AbstractHandler.php');
require_once(__DIR__ . '/../assistant/Assistant.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Czat z asystentem AI (widżet na każdej stronie dla zalogowanych — \assistant\isAvailable; tylko załączniki są dla patronów). Cienki handler — logika w src/inc/assistant/.
 * Jedna ciągła rozmowa na użytkownika; ta sama skrzynka przyjmuje powiadomienia systemowe (\chat\notify()).
 */
class ChatHandler extends AbstractHandler {
    private const PAGE = 50;

    /** Użytkownik z żądania, o ile ma dostęp do asystenta; inaczej 403 (widżet w JS ukrywa się po nie-200). */
    private function chatUser(Request $request): \user\User {
        $user = $request->getAttribute('user');
        if (!\assistant\isAvailable($user))
            throw new \Exception('Uprzejmy Asystent jest dostępny dla zalogowanych użytkowników.', 403);
        return $user;
    }

    /** GET /api/chat[?before=ID] — historia (od najstarszej). Pierwsza strona oznacza powiadomienia jako przeczytane. */
    public function history(Request $request, Response $response, array $args): Response {
        $email = $this->chatUser($request)->getEmail();
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
        $user = $this->chatUser($request);
        return $this->renderJson($response, [
            'unread' => \chat\unread($user->getEmail()),
            'canAttach' => \assistant\attachments\isAllowed($user),
        ]);
    }

    /**
     * POST /api/chat {message, attachments?: [{name, data}]} — pytanie do asystenta; odpowiedź synchronicznie.
     * Załączniki (JPEG/PNG/PDF) tylko dla patronów; każdy plik zużywa kredyt zdjęć (402 przy wyczerpaniu).
     */
    public function send(Request $request, Response $response, array $args): Response {
        $user = $this->chatUser($request);
        $body = $request->getParsedBody() ?? [];
        $attachments = $body['attachments'] ?? [];
        // Sama wiadomość jest wymagana tylko bez załączników (z plikami wystarczy plik).
        $text = $attachments ? (string)($body['message'] ?? '') : (string)$this->getParam($body, 'message');
        try {
            $reply = \assistant\reply($user, $text, is_array($attachments) ? $attachments : [$attachments]);
        } catch (\assistant\ChatException $e) {
            throw new \Exception($e->getMessage(), $e->getCode(), $e);
        }
        return $this->renderJson($response, ['message' => $reply]);
    }

    /**
     * GET /api/chat/photos?ids=a,b,c — ZIP ze zdjęciami zgłoszeń (link z narzędzia photos_package; tylko własne zgłoszenia).
     * Zwykła trasa bez JsonMiddleware (odpowiedź to plik).
     */
    public function photos(Request $request, Response $response, array $args): Response {
        $user = $this->chatUser($request);
        $ids = array_filter(explode(',', (string)($request->getQueryParams()['ids'] ?? '')));
        $apps = \assistant\tools\ownReports($user, $ids);
        if (!$apps) throw new \Exception('Nie znaleziono zgłoszeń ze zdjęciami.', 404);
        return self::renderXls($response, \assistant\tools\photosZip($apps), 'Zdjecia_zgloszen.zip')
            ->withHeader('Content-Type', 'application/zip');
    }

    /** DELETE /api/chat — „Nowa rozmowa”: archiwizuje bieżącą rozmowę (z powiadomieniami); historia zostaje w bazie. */
    public function clear(Request $request, Response $response, array $args): Response {
        return $this->renderJson($response, ['archived' => \chat\archive($this->chatUser($request)->getEmail())]);
    }
}
