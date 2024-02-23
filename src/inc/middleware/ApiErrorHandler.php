<?php
use Slim\Exception\HttpInternalServerErrorException;

/**
 * @SuppressWarnings(PHPMD.MissingImport)
 */
function ApiErrorHandler($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        // This error code is not included in error_reporting, so let it fall
        // through to the standard PHP error handler
        return false;
    }
    // $errstr may need to be escaped:
    $errstr = htmlspecialchars($errstr);

    throw new Exception("[$errno] $errstr. $errfile:$errline");
}