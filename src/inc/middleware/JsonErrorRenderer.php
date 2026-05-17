<?php
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorRendererInterface;

class JsonErrorRenderer implements ErrorRendererInterface {
    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string {
        return exceptionToErrorJson($exception);
    }
}

function exceptionToErrorJson($exception): string {
    $code = $exception->getCode();
    if ($exception instanceof HttpException) {
        $code = $exception->getCode();
    }
    if (!is_int($code) || $code < 100 || $code > 599) {
        $code = 500;
    }

    $response = Array(
        "error" => $exception->getMessage(),
        "status" => $code
    );

    if ($exception instanceof HttpException)
        $response["description"] = $exception->getDescription();
    if ($exception->getPrevious() instanceof MissingParamException)
        $response["param"] = $exception->getPrevious()->getParam();
    if (!isProd()) {
        $response["location"] = $exception->getFile() . ":" . $exception->getLine();
    }
    $previous = $exception->getPrevious();
    if ($previous) {
        $response["reason"] = $previous->getMessage();
    }
    return json_encode($response, JSON_UNESCAPED_UNICODE);
}
