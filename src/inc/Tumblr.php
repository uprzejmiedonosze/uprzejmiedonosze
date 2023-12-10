<?PHP
require_once(__DIR__ . '/../../vendor/autoload.php');

use Tumblr\API\Client as Tumblr;

function addToTumblr(&$app){
    $root = '/var/www/%HOST%/';

    $client = new Tumblr(
        'lc2iFjN4b63gjxGrBvfbUCbIjDYkr2ofvpOrOb83oMuBRfETsP',
        'ViFzg3ewByVMvO0hyOIu3ZgBC8nVma1C0WwqoldhOCTDWVYcYL',
        'S5qgkho10AT22hUJEAvKBxKop6wLJ4e0pcAymUQKVPmSSHrqkp',
        '9plPRCyDlrtqbYbvuGBITiFCHqWs2QFcLWjpXGcGA0UxwCKVxo'
      );
    $blogName = 'uprzejmie-donosze';

    $data = array(
        'type' => 'photo', 
        'caption' => "**{$app->carInfo->plateId}** {$app->address->city} --- {$app->getCategory()->getShort()}"
            . ( ( $app->userComment ) ? ' ' . $app->userComment: '' ) . "\n\n*-- {$app->getMonthYear()}*",
        //'source' => "%HTTPS%://%HOST%/{$app->contextImage->url}",
        "data64" => base64_encode(file_get_contents("$root/{$app->contextImage->url}")),
        'format' => 'markdown',
        'tags' => "{$app->carInfo->plateId}, {$app->address->city}",
        'state' => 'published',
        'date' => $app->date
        );
        

    return $client->createPost($blogName, $data);
}

?>