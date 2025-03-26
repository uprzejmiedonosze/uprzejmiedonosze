<?PHP
require_once(__DIR__ . '/../../vendor/autoload.php');

use app\Application;
use Tumblr\API\Client as Tumblr;

function addToTumblr(Application $app): stdClass|array {
    $client = new Tumblr(
        'lc2iFjN4b63gjxGrBvfbUCbIjDYkr2ofvpOrOb83oMuBRfETsP',
        'ViFzg3ewByVMvO0hyOIu3ZgBC8nVma1C0WwqoldhOCTDWVYcYL',
        'S5qgkho10AT22hUJEAvKBxKop6wLJ4e0pcAymUQKVPmSSHrqkp',
        '9plPRCyDlrtqbYbvuGBITiFCHqWs2QFcLWjpXGcGA0UxwCKVxo'
      );
    $blogName = 'uprzejmie-donosze';
    $recydywa = "";
    if ($app->getRecydywa()->appsCnt > 1)
        $recydywa = "*(recydywa {$app->getRecydywa()->appsCnt})*";
    $description = $app->getCategory()->getFormal()
        . " "
        . $app->getExtensionsText();
    $data = array(
        'type' => 'photo', 
        'caption' => "**{$app->carInfo->plateId}** $recydywa — {$description}"
            . "Zgłoszone do {$app->guessSMData()->getShortName()}"
            . "\n\n*-- {$app->getDate("LLLL y")}*",
        "data64" => base64_encode(file_get_contents(ROOT . "{$app->contextImage->url}")),
        'format' => 'markdown',
        'tags' => "{$app->carInfo->plateId}, {$app->guessSMData()->getShortName()}",
        'state' => 'published',
        'date' => $app->date
        );
        
    return $client->createPost($blogName, $data);
}
