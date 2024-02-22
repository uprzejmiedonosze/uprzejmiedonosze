<?php
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorRendererInterface;

class JsonErrorRenderer implements ErrorRendererInterface {
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string {
        return json_encode(exceptionToErrorJson($exception), JSON_UNESCAPED_UNICODE);
    }
}

function exceptionToErrorJson($exception): array {
    $code = $exception->getCode() ?? 500;
    $response = Array(
        "error" => $exception->getMessage(),
        "status" => $code
    );
    if (isProd() && $code !== 404) \Sentry\captureException($exception);
    if ($exception instanceof HttpException)
        $response["description"] = $exception->getDescription();
    if ($exception instanceof MissingParamException)
        $response["param"] = $exception->getParam();
    if (!isProd()) {
        $response["location"] = $exception->getFile() . ":" . $exception->getLine();
    }
    $previous = $exception->getPrevious();
    if ($previous) {
        $response["reason"] = $previous->getMessage();
    }
    return $response;
}
