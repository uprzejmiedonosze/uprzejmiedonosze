<?PHP
require(__DIR__ . '/../../vendor/autoload.php');

function addToTumblr($app){
    $client = new Tumblr\API\Client(
        'lc2iFjN4b63gjxGrBvfbUCbIjDYkr2ofvpOrOb83oMuBRfETsP',
        'ViFzg3ewByVMvO0hyOIu3ZgBC8nVma1C0WwqoldhOCTDWVYcYL',
        'S5qgkho10AT22hUJEAvKBxKop6wLJ4e0pcAymUQKVPmSSHrqkp',
        '9plPRCyDlrtqbYbvuGBITiFCHqWs2QFcLWjpXGcGA0UxwCKVxo'
      );
    $blogName = 'uprzejmie-donosze';

    $recydywa = '';
    if($app->getRecydywa > 1){
        $recydywa = ' (recydywista)';
    }

    $data = array(
        'type' => 'photo', 
        'caption' => "**{$app->carInfo->plateId}**$recydywa, {$app->address->city}\n\n{$app->getCategory()->getTitle()}\n\n![mapa]({$app->getMapImage()})",
        'source' => $app->contextImage->url,
        'format' => 'markdown',
        'tags' => "{$app->address->city}, {$app->carInfo->plateId}",
        'state' => 'queue',
        'date' => $app->date
        );
        
    try {
        print_r($client->createPost($blogName, $data));
    } catch (Exception $ex) {
        print_r($ex);
    }
}
 

?>