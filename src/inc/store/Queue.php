<?php namespace queue;

use Slowmove\SimplePhpQueue\Queue;
use Slowmove\SimplePhpQueue\Storage\StorageType;

$queue = null;

function queue(): Queue 
{
    global $queue;
    if ($queue)
        return $queue;

    $queueFile = dirname(DB_FILENAME) . '/queue.sqlite';
    $pdo = new \PDO('sqlite:' . $queueFile);
    $pdo->exec("CREATE TABLE IF NOT EXISTS queue (id INTEGER PRIMARY KEY AUTOINCREMENT, data TEXT);");

    $queue = new Queue(StorageType::SQLITE, $queueFile);
    return $queue;
}

function produce(string $msg): bool
{
    return queue()->enqueue($msg);
}

function consume(callable $fn): void
{
    queue()->listen($fn);
}
