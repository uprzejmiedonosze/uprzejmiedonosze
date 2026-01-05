<?PHP
require_once(__DIR__ . '/AbstractHandler.php');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

class DocHandler extends AbstractHandler {

    public function doc(Request $request, Response $response): Response {
        global $TARGETS;
        $user = $request->getAttribute('user');
        $petitionId = $request->getAttribute('petitionId');
        $petition = \generator\get($petitionId);

        if ($petition->email !== $user->getEmail()) {
            throw new HttpForbiddenException($request, "Użytkownik '{$user->getEmail()}' nie jest właścicielem petycji '$petitionId'");
        }

        if (!empty($petition->recipient)) {
            $mps = \generator\ApiAiHandler::__getParlamentary($user);
            $target = $mps[$petition->recipient]['formal'] . "\n\n";
        } else {
            $target = $TARGETS[$petition->target]['formal'] . "\n\n";
        }

        $signature = "\n\n{$user->data->name}\n{$user->data->address}";

        $doc = \generator\Petition2Doc($petition, $target, $signature);
        return AbstractHandler::renderDoc($response, $doc, $user->getSanitizedName().".docx");
    }
}