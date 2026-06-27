<?php

define('PHPUNIT_RUNNING', true);
define('DB_FILENAME', getenv('TEST_DB') ?: __DIR__ . '/../services/devroot/db/store.sqlite');

require(__DIR__ . '/../export/inc/include.php');
require(__DIR__ . '/../export/inc/Twig.php');
require_once(__DIR__ . '/DatabaseTestCase.php');

$GLOBALS['STATUSES'] = $STATUSES;
$GLOBALS['SM_ADDRESSES'] = $SM_ADDRESSES;
$GLOBALS['POLICE_ADDRESSES'] = $POLICE_ADDRESSES;
$GLOBALS['STOP_AGRESJI'] = $STOP_AGRESJI;
$GLOBALS['CATEGORIES'] = $CATEGORIES;
$GLOBALS['LEVELS'] = $LEVELS;
$GLOBALS['BADGES'] = $BADGES;
$GLOBALS['cache'] = $cache;
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
$_SESSION = [];

function exception_error_handler(int $errno, string $errstr, ?string $errfile = null, ?int $errline = null) {
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler(exception_error_handler(...));
