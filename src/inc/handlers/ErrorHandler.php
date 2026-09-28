<?PHP
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\App;

function getCustomErrorHandler(App $app): callable {
    return function (
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
        ?LoggerInterface $logger = null
    ) use ($app) {
        $status = $exception->getCode();
        if ($exception instanceof HttpException) {
            $status = $exception->getCode();
        }
        if (!is_int($status) || $status < 100 || $status > 599) {
            $status = 500;
        }

        $email = $_SESSION['user_email'] ?? 'niezalogowany';
        $msg = $exception->getMessage() . " [$email], " . trimAbsolutePaths($exception->getFile())
            . ':' . $exception->getLine();

        if (isProd() && $status >= 500 && $status !== 503) \Sentry\captureException($exception);
        if ($status === 404) {
            log_debug($msg);
        } elseif ($status >= 500 && $status !== 503) {
            log_error($msg, $exception);
        } else {
            log_info($msg, true);
        }
        
        $httpException = $exception;
        if (!($exception instanceof HttpException)) {
            $httpException = new HttpException($request, $exception->getMessage(), $status, $exception);
        }
        $response = $app->getResponseFactory()->createResponse();

        $accept = $request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json') || str_contains($accept, 'text/event-stream')) {
            $payload = exceptionToErrorJson($httpException);
        } else {
            $payload = exceptionToErrorHtml($httpException);
            
        }
        $response->getBody()->write($payload);
        return $response->withStatus($status);
    };
}
