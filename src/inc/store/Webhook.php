<?PHP namespace webhook;

const TABLE = 'webhooks';

/**
 * Store a web-hook before processing it.
 */
function add(string $id, array $event): void {
    $event['processed'] = false;
    \store\set(TABLE, $id, json_encode($event));
}

/**
 * Mark web-hook as processed.
 */
function mark(string $id, ?string $reason=null): void {
    $event = json_decode(\store\get(TABLE, $id), true);
    $event['processed'] = true;
    if ($reason) $event['reason'] = $reason;
    \store\set(TABLE, $id, json_encode($event));
}
