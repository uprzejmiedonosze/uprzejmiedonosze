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
    $response = Array(
        "error" => $exception->getMessage(),
        "status" => $exception->getCode()
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
    return json_encode($response, JSON_UNESCAPED_UNICODE);
}
