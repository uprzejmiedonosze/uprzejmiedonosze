<?php

require('../../vendor/autoload.php');

$options = getopt("f:");
$imageBytes = file_get_contents($options['f']);
$imageBytes = base64_encode($imageBytes);
$imageHash = sha1($imageBytes);
echo $imageHash;

$search = new \Qmegas\MemcacheSearch();
$search->addServer('127.0.0.1', 11211);

$cache = new Memcache;
$cache->connect('localhost', 11211);

$find = new \Qmegas\Finder\Inline($imageHash);


foreach ($search->search($find) as $item) {
	$key = $item->getKey();
	$value = $cache->get($key);
	print_r($value);
}