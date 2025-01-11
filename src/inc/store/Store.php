<?php namespace store;

if (!defined('DB_FILENAME'))
    define('DB_FILENAME', __DIR__ . '/../../../db/store.sqlite');

$store = null;

function store(): \PDO
{
    global $store;
    if ($store)
        return $store;
    $store = new \PDO('sqlite:' . DB_FILENAME);
    $store->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    return $store;
}


function query(string $query, int|null $fetchMode = null, mixed ...$fetch_mode_args): \PDOStatement|false
{
    return store()->query($query, $fetchMode, $fetch_mode_args);
}

function prepare(string $query, array $options = []): \PDOStatement|false
{
    return store()->prepare($query, $options);
}

function dump(\PDOStatement $stmt)
{
    if(isStaging()) {
        ob_start();
        $stmt->debugDumpParams();
        logger(ob_get_clean(), true);
    }
}

function get(string $table, string $key): string|null
{
    if (!is_string($key))
        throw new \InvalidArgumentException('Expected string as key');

    $stmt = store()->prepare(
        "SELECT value FROM $table WHERE key = :key;"
    );
    $stmt->bindParam(':key', $key, \PDO::PARAM_STR);
    $stmt->execute();

    $value = $stmt->fetch(\PDO::FETCH_COLUMN);
    if (!$value) return null;
    return $value;
}

function set(string $table, string $key, string $value): string
{
    if (!is_string($key))
        throw new \InvalidArgumentException('Expected string as key');

    if (!is_string($value))
        throw new \InvalidArgumentException('Expected string as value');

    $queryString = "REPLACE INTO $table VALUES (:key, :value);";
    $stmt = store()->prepare($queryString);
    $stmt->bindParam(':key', $key, \PDO::PARAM_STR);
    $stmt->bindParam(':value', $value, \PDO::PARAM_STR);
    $stmt->execute();

    return $value;
}

function delete(string $table, string $key): void
{
    $stmt = store()->prepare(
        "DELETE FROM $table WHERE key = :key;"
    );
    $stmt->bindParam(':key', $key, \PDO::PARAM_STR);
    $stmt->execute();
}
