<?php

define('PHPUNIT_RUNNING', true);
define('DB_FILENAME', getenv('TEST_DB') ?: __DIR__ . '/../services/devroot/db/store.sqlite');

// OAuth-server tests (consent, token exchange) need a private + encryption key.
// The default test profile configures none, so generate ephemeral ones here —
// before config.php runs — so those tests run everywhere instead of skipping.
if (!defined('OAUTH_PRIVATE_KEY') && function_exists('openssl_pkey_new')) {
    $oauthKey = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($oauthKey && @openssl_pkey_export($oauthKey, $oauthPem)) {
        define('OAUTH_PRIVATE_KEY', $oauthPem);
        define('OAUTH_ENCRYPTION_KEY', base64_encode(random_bytes(32)));
    }
}

require(__DIR__ . '/../export/inc/include.php');
require(__DIR__ . '/../export/inc/Twig.php');
require_once(__DIR__ . '/DatabaseTestCase.php');

$GLOBALS['STATUSES'] = $STATUSES;
$GLOBALS['SM_ADDRESSES'] = $SM_ADDRESSES;
$GLOBALS['POLICE_ADDRESSES'] = $POLICE_ADDRESSES;
$GLOBALS['STOP_AGRESJI'] = $STOP_AGRESJI;
$GLOBALS['CATEGORIES'] = $CATEGORIES;
$GLOBALS['EXTENSIONS'] = $EXTENSIONS;
$GLOBALS['LEVELS'] = $LEVELS;
$GLOBALS['BADGES'] = $BADGES;
$GLOBALS['cache'] = $cache;
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
$_SESSION = [];

function exception_error_handler(int $errno, string $errstr, ?string $errfile = null, ?int $errline = null) {
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler(exception_error_handler(...));
