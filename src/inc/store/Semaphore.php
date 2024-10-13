<?php namespace semaphore;

use SysvSemaphore;

function acquire(int $semKey): SysvSemaphore|false {
    $semaphore = sem_get($semKey, 1, 0666, 1);
    sem_acquire($semaphore);
    return $semaphore;
}

function release(SysvSemaphore $semaphore): bool {
    return sem_release($semaphore);
}

