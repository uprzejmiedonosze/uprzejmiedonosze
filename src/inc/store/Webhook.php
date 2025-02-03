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
    $event = get($id);
    $event['processed'] = true;
    if ($reason) $event['reason'] = $reason;
    \store\set(TABLE, $id, json_encode($event));
}

function get(string $id): ?array {
    return json_decode(\store\get(TABLE, $id), true);
}

function getUnprocessed(): array {
    $sql = <<<SQL
        select key
        from %s
        where json_extract(value, '$.processed') = false;
    SQL;
    $sql = sprintf($sql, TABLE);
    $stmt = \store\prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_COLUMN);
}

