<?php
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorRendererInterface;

class ErrorRenderer implements ErrorRendererInterface {
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string {
        $code = $exception->getCode();
        $response = Array(
            "error" => $exception->getMessage(),
            "status" => ($code ? $code : 500)
        );
        if ($exception instanceof HttpException)
            $response["description"] = $exception->getDescription();
        if (!isProd()) {
            $response["location"] = $exception->getFile() . ":" . $exception->getLine();
        }
        $previous = $exception->getPrevious();
        if ($previous) {
            $response["reason"] = $previous->getMessage();
        }
        return json_encode($response, JSON_PRETTY_PRINT); // JSON_UNESCAPED_UNICODE
    }
}
