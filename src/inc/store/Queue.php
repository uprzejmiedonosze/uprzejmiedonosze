<?php namespace queue;

/**
 * Minimal SQLite-backed FIFO queue (db/queue.sqlite), shared by the web workers
 * (producers) and the face-detect-consumer daemon.
 *
 * Replaces slowmove/simple-php-queue: its SQLite3 connection has busy_timeout=0,
 * so any concurrent write made the consumer's SELECT fail instantly with
 * "database is locked". Here every connection waits up to BUSY_TIMEOUT_MS and
 * dequeue is a single atomic DELETE ... RETURNING (no SELECT+DELETE race).
 * The table layout is unchanged, so existing queue.sqlite files keep working.
 */

const BUSY_TIMEOUT_MS = 5000;
const EMPTY_DELAY_SECONDS = 5;

$queue = null;

function open(string $path): \SQLite3
{
    $db = new \SQLite3($path);
    $db->busyTimeout(BUSY_TIMEOUT_MS);
    $db->exec("CREATE TABLE IF NOT EXISTS queue (id INTEGER PRIMARY KEY AUTOINCREMENT, data TEXT);");
    return $db;
}

function queue(): \SQLite3
{
    global $queue;
    if ($queue)
        return $queue;

    $queue = open(ROOT . 'db/queue.sqlite');
    return $queue;
}

function produce(string $msg): bool
{
    return enqueue(queue(), $msg);
}

function enqueue(\SQLite3 $db, string $msg): bool
{
    $stmt = $db->prepare("INSERT INTO queue (data) VALUES (:data)");
    if (!$stmt)
        return false;
    $stmt->bindValue(':data', $msg, SQLITE3_TEXT);
    return (bool)$stmt->execute();
}

/**
 * Removes and returns the oldest message, or null when the queue is empty
 * (or the database stayed locked for longer than BUSY_TIMEOUT_MS).
 */
function dequeue(\SQLite3 $db): ?string
{
    $data = $db->querySingle(
        "DELETE FROM queue WHERE id = (SELECT id FROM queue ORDER BY id ASC LIMIT 1) RETURNING data"
    );
    return is_string($data) ? $data : null;
}

function consume(callable $fn): void
{
    $db = queue();
    while (true) {
        $item = dequeue($db);
        if ($item !== null)
            $fn($item);
        else
            sleep(EMPTY_DELAY_SECONDS);
    }
}
