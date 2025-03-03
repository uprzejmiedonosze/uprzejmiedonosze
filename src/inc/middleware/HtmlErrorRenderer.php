<?php
use Slim\Interfaces\ErrorRendererInterface;


class HtmlErrorRenderer implements ErrorRendererInterface {
    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string {
        return exceptionToErrorHtml($exception);
    }
}

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function exceptionToErrorHtml($exception): string {    
    $status = $exception->getCode();
    $twig = initBareTwig();

    $template = ($status == 404) ? '404' : 'error';
    $parameters = HtmlMiddleware::getDefaultParameters();
    $parameters['exception'] = $exception;
    $parameters['BASE_URL'] = BASE_URL;
    $parameters['CSS_HASH'] = CSS_HASH;
    $parameters['JS_HASH'] = JS_HASH;
    $parameters['email'] = $_SESSION['user_email'] ?? null;
    $parameters['config']['sex'] = SEXSTRINGS['?'];

    return $twig->render("$template.html.twig", $parameters);
}

set_exception_handler('exceptionToErrorHtml');
