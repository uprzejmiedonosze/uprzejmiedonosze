<?PHP

use app\Application;
use Tumblr\API\Client as Tumblr;

function addToTumblr(Application $app): stdClass|array {
    // config.php now always defines TUMBLR_CONSUMERKEY (getenv() ?: '',
    // same pattern as every other optional integration secret there) —
    // !defined() alone would never be true anymore, so check emptiness
    // instead. Same graceful "skip the real post" behavior for dev/staging
    // (or prod if the key is ever genuinely unset) as before.
    if (empty(TUMBLR_CONSUMERKEY)) {
        log_debug("TUMBLR_CONSUMERKEY not set, skipping real post");
        return new JSONObject(array("id" => "fake", "state" => "published"));
    }

    $client = new Tumblr(TUMBLR_CONSUMERKEY, TUMBLR_CONSUMERSECRET, TUMBLR_TOKEN, TUMBLR_SECRET);
    $blogName = 'uprzejmie-donosze';
    $recydywa = "";
    if ($app->getRecydywa()->appsCnt > 1)
        $recydywa = "*(recydywa {$app->getRecydywa()->appsCnt})*";
    $description = $app->getCategory()->getFormal()
        . " "
        . $app->getExtensionsText();
    $app->ensureLocal();
    $image = file_get_contents(ROOT . "{$app->contextImage->url}");
    if (\faces\hasBoxes($app->faces ?? null))
        $image = \faces\blur($image, $app->faces->boxes);
    $data = array(
        'type' => 'photo',
        'caption' => "**{$app->carInfo->plateId}** $recydywa — {$description}"
            . "Zgłoszone do {$app->guessSMData()->getShortName()}"
            . "\n\n*-- {$app->getDate("LLLL y")}*",
        "data64" => base64_encode($image),
        'format' => 'markdown',
        'tags' => "{$app->carInfo->plateId}, {$app->guessSMData()->getShortName()}",
        'state' => 'published',
        'date' => $app->date
    );

    $result = $client->createPost($blogName, $data);
    return $result;
}

function addToGallery(\app\Application $app): \app\Application {
    $canImageBeShown = $app->canImageBeShown(whoIsWathing: null);
    $facesCount = $app->faces->count ?? 0;
    $alreadyInGallery = isset($app->addedToGallery);
    $plateId = $app->carInfo->plateId;

    log_debug("addToGallery plate:$plateId faces:$facesCount canImageBeShown:$canImageBeShown alreadyInGallery:$alreadyInGallery");

    if ($alreadyInGallery) return $app;
    if ($facesCount > 0 && !\faces\hasBoxes($app->faces ?? null)) return $app;
    if (!$canImageBeShown) return $app;

    $app->addedToGallery = \addToTumblr($app);

    log_info("https://galeria.uprzejmiedonosze.net/post/" . $app->addedToGallery->id, true);

    if (!($app->contextImage->galleryReady ?? false)) {
        $app->generateGalleryImages();
        \app\markGalleryReady($app->id, $app->carInfo->plateId ?? null);
    }

    $app->addComment("admin", "Zdjęcie dodane do galerii.");
    return $app;
}
