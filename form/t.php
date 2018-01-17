<?php
date_default_timezone_set('Europe/Warsaw');
require(__DIR__ . '/../vendor/autoload.php');

use Kreait\Firebase\Factory;
$firebase = (new Factory)->create();

?>