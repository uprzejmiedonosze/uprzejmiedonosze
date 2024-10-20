<?php namespace store;


if (!defined('DB_FILENAME'))
    define('DB_FILENAME', __DIR__ . '/../../../db/store.sqlite');

const KEY = 'key';

$store = new \PDO('sqlite:' . DB_FILENAME);
$store->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function query(string $query, int|null $fetchMode = null, mixed ...$fetch_mode_args): \PDOStatement|false
{
    global $store;
    return $store->query($query, $fetchMode, $fetch_mode_args);
}

function prepare(string $query, array $options = []): \PDOStatement|false
{
    global $store;
    return $store->prepare($query, $options);
}

function get(string $table, string $key): string|null
{
    global $store;
    if (!is_string($key)) {
        throw new \InvalidArgumentException('Expected string as key');
    }

    $stmt = $store->prepare(
        'SELECT * FROM ' . $table . ' WHERE ' . KEY
        . ' = :key;'
    );
    $stmt->bindParam(':key', $key, \PDO::PARAM_STR);
    $stmt->execute();

    $row = $stmt->fetch(\PDO::FETCH_NUM);
    if ($row)
        return $row[1];

    return null;
}

function set(string $table, string $key, string $value, string $email = null): string
{
    global $store;
    if (!is_string($key)) {
        throw new \InvalidArgumentException('Expected string as key');
    }

    if (!is_string($value)) {
        throw new \InvalidArgumentException('Expected string as value');
    }

    $queryString = 'REPLACE INTO ' . $table . ' VALUES (:key, :value);';
    if ($email) {
        $queryString = 'REPLACE INTO ' . $table . ' VALUES (:key, :value, :email);';
    }
    $stmt = $store->prepare($queryString);
    $stmt->bindParam(':key', $key, \PDO::PARAM_STR);
    $stmt->bindParam(':value', $value, \PDO::PARAM_STR);
    if ($email) {
        $stmt->bindParam(':email', $email, \PDO::PARAM_STR);
    }
    $stmt->execute();

    return $value;
}

function delete(string $table, string $key): void
{
    global $store;
    $stmt = $store->prepare(
        'DELETE FROM ' . $table . ' WHERE ' . KEY
        . ' = :key;'
    );
    $stmt->bindParam(':key', $key, \PDO::PARAM_STR);
    $stmt->execute();
}
