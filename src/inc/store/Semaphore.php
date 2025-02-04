<?php namespace semaphore;

const SEMAPHORE_WAIT = 30;

function acquire(string $semKey, string $source): void {
    logger("Acquiring semaphore $semKey from $source", true);
    $limit = SEMAPHORE_WAIT+10;
    while (!\cache\add(type:\cache\Type::Semaphore, key:$semKey, value:1, flag:0, expire:SEMAPHORE_WAIT)) {
        usleep(1000);
        logger("Awaiting semaphore $semKey from $source", true);
        if ($limit-- < 0)
            throw new \Exception("Error semaphore $semKey is locked.");
    }
}

function release(string $semKey, string $source): void {
    logger("Releasing semaphore $semKey from $source", true);
    \cache\delete(type:\cache\Type::Semaphore, key:$semKey);
}
