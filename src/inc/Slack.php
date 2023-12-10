<?PHP
require __DIR__ . '/../../vendor/autoload.php';

$queue = msg_get_queue(9997);

$channels = [
    1 => 'https://hooks.slack.com/services/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', // prod
    2 => 'https://hooks.slack.com/services/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', // prod
    11 => 'https://hooks.slack.com/services/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',// staging
    12 => 'https://hooks.slack.com/services/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' // staging
];

$msg = NULL;
$type = NULL;
while(msg_receive($queue, 0, $type, 20000, $msg)){
    if($channel = @$channels[intval($type)]){
        $client = new Maknz\Slack\Client($channel);
        if(is_array($msg)){
            $client->attach($msg)->send("");
        }else{
            $client->send($msg);
        }
    }
    $msg = NULL;
    $type = NULL;
}

?>