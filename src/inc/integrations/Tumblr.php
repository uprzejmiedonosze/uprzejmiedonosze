<?PHP

use app\Application;
use Tumblr\API\Client as Tumblr;

function addToTumblr(Application $app): stdClass|array {
    $client = new Tumblr(TUMBLR_CONSUMERKEY, TUMBLR_CONSUMERSECRET, TUMBLR_TOKEN, TUMBLR_SECRET);
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

function addToGallery(\app\Application $app): \app\Application {
    $canImageBeShown = $app->canImageBeShown(whoIsWathing: null);
    $facesCount = $app->faces->count ?? 0;
    $alreadyInGallery = isset($app->addedToGallery);
    logger("addToGallery faces:$facesCount canImageBeShown: $canImageBeShown alreadyInGallery:$alreadyInGallery", true);

    if ($alreadyInGallery) return $app;
    if ($facesCount > 0) return $app;
    if (!$canImageBeShown) return $app;

    if (isProd())
        $app->addedToGallery = \addToTumblr($app);
    else
        $app->addedToGallery = new JSONObject(array("id" => "fake", "state" => "published"));

    logger("https://galeria.uprzejmiedonosze.net/post/" . $app->addedToGallery->id, true);

    $app->addComment("admin", "Zdjęcie dodane do galerii.");
    return $app;
}
