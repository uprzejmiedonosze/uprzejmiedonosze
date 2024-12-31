<?php

define('DB_FILENAME', __DIR__ . '/../docker/db/store.sqlite');

require(__DIR__ . '/../export/inc/include.php');
require(__DIR__ . '/../export/inc/Twig.php');

$GLOBALS['STATUSES'] = $STATUSES;
$GLOBALS['SM_ADDRESSES'] = $SM_ADDRESSES;
$GLOBALS['CATEGORIES'] = $CATEGORIES;
$GLOBALS['cache'] = $cache;
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';