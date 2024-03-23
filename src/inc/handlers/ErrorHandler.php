<?PHP
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\App;
use Slim\Interfaces\ErrorHandlerInterface;

function getCustomErrorHandler(App $app): callable {
    return function (
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
        ?LoggerInterface $logger = null
    ) use ($app) {
        if ($logErrors) {
            logger($exception->getMessage());
        }
        $status = $exception->getCode() ?? 500;
        $httpException = new HttpException($request, $exception->getMessage(), $status, $exception);

        $response = $app->getResponseFactory()->createResponse();

        $accept = $request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json')) {
            $payload = exceptionToErrorJson($httpException);
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        } else {
            $payload = exceptionToErrorHtml($httpException);
            $response->getBody()->write($payload);
        }
        return $response->withStatus($status);
    };
}
