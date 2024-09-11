<?PHP namespace webhook;

const TABLE = 'webhooks';

/**
 * Store a web-hook before processing it.
 */
function add(string $id, array $event): void {
    logger("saving new addWebhook $id");
    $event['processed'] = false;
    \store\set(TABLE, $id, json_encode($event));
}

/**
 * Mark web-hook as processed.
 */
function mark(string $id): void {
    logger("marking addWebhook $id as done");
    $event['processed'] = true;
    $event = json_decode(\store\get(TABLE, $id));
}
