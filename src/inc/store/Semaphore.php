<?php namespace semaphore;

const SEMAPHORE_WAIT = 30;

function acquire(string $semKey, string $source): void {
    $limit = SEMAPHORE_WAIT+10;
    while (!tryAcquire($semKey)) {
        sleep(1);
        logger("Awaiting semaphore $semKey from $source", true);
        if ($limit-- < 0)
            throw new \Exception("Error semaphore $semKey is locked.");
    }
}

function tryAcquire(string $semKey): bool {
    return \cache\add(type:\cache\Type::Semaphore, key:$semKey, value:1, flag:0, expire:SEMAPHORE_WAIT);
}

function release(string $semKey, string $source): void {
    \cache\delete(type:\cache\Type::Semaphore, key:$semKey);
}

function withLock(string $semKey, string $source, callable $fn): mixed {
    acquire($semKey, $source);
    try {
        return $fn();
    } finally {
        release($semKey, $source);
    }
}
