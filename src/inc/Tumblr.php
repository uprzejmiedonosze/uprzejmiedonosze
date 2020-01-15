<?PHP
require_once(__DIR__ . '/../vendor/autoload.php');

function addToTumblr(&$app){
    $client = new Tumblr\API\Client(
        'lc2iFjN4b63gjxGrBvfbUCbIjDYkr2ofvpOrOb83oMuBRfETsP',
        'ViFzg3ewByVMvO0hyOIu3ZgBC8nVma1C0WwqoldhOCTDWVYcYL',
        'S5qgkho10AT22hUJEAvKBxKop6wLJ4e0pcAymUQKVPmSSHrqkp',
        '9plPRCyDlrtqbYbvuGBITiFCHqWs2QFcLWjpXGcGA0UxwCKVxo'
      );
    $blogName = 'uprzejmie-donosze';

    $data = array(
        'type' => 'photo', 
        'caption' => "**{$app->carInfo->plateId}** {$app->address->city} --- {$app->getCategory()->getShort()}"
            . ( ( $app->userComment ) ? ' ' . $app->userComment: '' ) . "\n\n{$app->getMonthYear()}",
        'source' => "%HTTPS%://%HOST%/{$app->contextImage->url}",
        'format' => 'markdown',
        'tags' => "{$app->address->city}, {$app->carInfo->plateId}, {$app->getYM()}",
        'state' => 'published',
        'date' => $app->date
        );
        

    return $client->createPost($blogName, $data);
}

?>