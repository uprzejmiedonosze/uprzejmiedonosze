<?php

require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../export/inc/include.php');
require_once(__DIR__ . '/../export/inc/Twig.php');

$twig = initBareTwig();
echo $twig->render('fail2ban.html.twig', [
    'BASE_URL' => BASE_URL,
    'CSS_HASH' => CSS_HASH,
    'JS_HASH' => JS_HASH,
    'dialog' => false,
    'general' => [
        'uri' => '/',
        'isProd' => true,
        'isStaging' => false,
        'isLoggedIn' => false
    ],
    'config' => [
        'menu' => null
    ]
]);
