<?php namespace queue;

use Slowmove\SimplePhpQueue\Queue;
use Slowmove\SimplePhpQueue\Storage\StorageType;

$queue = null;

function queue(): Queue 
{
    global $queue;
    if ($queue)
        return $queue;
    $queue = new Queue(StorageType::SQLITE, DB_FILENAME);
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
