<?php namespace semaphore;

const SEMAPHORE_WAIT = 30;

function acquire(string $semKey, string $source): void {
    $limit = SEMAPHORE_WAIT+10;
    while (!tryAcquire($semKey)) {
        sleep(1);
        // $force=true restored (2026-09-28 correction) — it's meant to
        // make this visible on prod too, that part was correct. What
        // shouldn't be prod-only-ish is the STACK TRACE dump, which is
        // now gated on environment inside logger() itself, not on $force.
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
