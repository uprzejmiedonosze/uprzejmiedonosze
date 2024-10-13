<?php

$options = getopt("f:");
$imageBytes = file_get_contents($options['f']);
$imageBytes = base64_encode($imageBytes);
$imageHash = sha1($imageBytes);
echo $imageHash;

