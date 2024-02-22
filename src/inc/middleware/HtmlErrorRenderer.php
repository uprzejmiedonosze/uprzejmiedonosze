<?php
use Slim\Interfaces\ErrorRendererInterface;


class HtmlErrorRenderer implements ErrorRendererInterface {
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string {
        return exceptionToErrorHtml($exception);
    }
}

function exceptionToErrorHtml($exception): string {
    if (isProd()) \Sentry\captureException($exception);
    try{
        $email = getCurrentUserEmail();
    }catch(Exception $e){
        $email = 'niezalogowany';
    }
    $msg = $exception->getMessage() . " szkodnik: $email, " . $exception->getFile()
        . ':' . $exception->getLine() . "\n" . $exception->getTraceAsString();
    
    if(posix_isatty(0)){
        echo($msg . "\n");
        return '';
    }
    $code = $exception->getCode() ?? 500;
    if (isProd() && $code !== 404) \Sentry\captureException($exception);
    $time = logger($msg, $code !== 404);

    $twig = initBareTwig();

    $template = 'error';
    if ($code == 404)
        $template = '404';

    $parameters = HtmlMiddleware::getDefaultParameters();
    $parameters['msg'] = $msg;
    $parameters['exception'] = $exception;
    $parameters['email'] = $email;
    $parameters['time'] = $time;
    
    return $twig->render("$template.html.twig", $parameters);
}

set_exception_handler('exceptionToErrorHtml');
