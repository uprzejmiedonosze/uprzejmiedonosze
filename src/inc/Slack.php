<?PHP
require __DIR__ . '/../vendor/autoload.php';

$queue = msg_get_queue(
    '%HOST%' != 'uprzejmiedonosze.net'? 8888: 9999
);

$channels = [
    'uprzejmiedonosze.net-1' => 'https://hooks.slack.com/services/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',
    'uprzejmiedonosze.net-2' => 'https://hooks.slack.com/services/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',
    'staging.uprzejmiedonosze.net-1' => 'https://hooks.slack.com/services/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',
    'staging.uprzejmiedonosze.net-2' => 'https://hooks.slack.com/services/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'
];

$msg = NULL;
$type = NULL;
while(msg_receive($queue, 0, $type, 2000, $msg)){
    if($channel = @$channels["%HOST%-$type"]){
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