<?php
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorRendererInterface;

class JsonErrorRenderer implements ErrorRendererInterface {
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string {
        return json_encode(exceptionToErrorJson($exception), JSON_UNESCAPED_UNICODE);
    }
}

function exceptionToErrorJson($exception): array {
    if (isProd()) \Sentry\captureException($exception);
    $code = $exception->getCode();
    $response = Array(
        "error" => $exception->getMessage(),
        "status" => ($code ? $code : 500)
    );
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
