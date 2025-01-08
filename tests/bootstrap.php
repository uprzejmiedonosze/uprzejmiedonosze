<?php

define('DB_FILENAME', __DIR__ . '/../docker/db/store.sqlite');

require(__DIR__ . '/../export/inc/include.php');
require(__DIR__ . '/../export/inc/Twig.php');

$GLOBALS['STATUSES'] = $STATUSES;
$GLOBALS['SM_ADDRESSES'] = $SM_ADDRESSES;
$GLOBALS['CATEGORIES'] = $CATEGORIES;
$GLOBALS['cache'] = $cache;
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';

function exception_error_handler(int $errno, string $errstr, string $errfile = null, int $errline) {
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler(exception_error_handler(...));
