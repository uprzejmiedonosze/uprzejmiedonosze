<?php

use \PDO as PDO;
use \PDOStatement as PDOStatement;
use \InvalidArgumentException as InvalidArgumentException;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class Store implements \Iterator, \Countable
{
    /**
     * @var PDO
     */
    private $db = null;

    /**
     * @var string
     */
    private $name = null;

    /**
     * @var string
     */
    private $keyColumnName = 'key';

    /**
     * @var string
     */
    private $valueColumnName = 'value';

    /**
     * @var array
     */
    private $data = array();

    /**
     * @var bool
     */
    private $isDataLoadedFromDb = false;

    /**
     * @var PDOStatement
     */
    private $iterator;

    /**
     * Current value during iteration
     * @var array
     */
    private $current = null;

    /**
     * @param PDO $db PDO database instance
     * @param string $name store name
     * @SuppressWarnings(PHPMD.ShortVariable)
     *
     * @return \NoSQLite\Store
     */
    public function __construct($db, $name)
    {
        $this->db = $db;
        $this->name = $name;
    }

    /**
     * @param string $key key
     *
     * @throws InvalidArgumentException
     * @return string|null
     */
    public function get($key)
    {
        if (!is_string($key)) {
            throw new InvalidArgumentException('Expected string as key');
        }

        if (isset($this->data[$key])) {
            return $this->data[$key];
        } elseif (!$this->isDataLoadedFromDb) {
            $stmt = $this->db->prepare(
                'SELECT * FROM ' . $this->name . ' WHERE ' . $this->keyColumnName
                . ' = :key;'
            );
            $stmt->bindParam(':key', $key, PDO::PARAM_STR);
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_NUM);
            if ($row) {
                $this->data[$row[0]] = $row[1];
                return $this->data[$key];
            }
        }

        return null;
    }

    /**
     * Get all values as array with key => value structure
     *
     * @return array
     */
    public function getAll()
    {
        if (!$this->isDataLoadedFromDb) {
            $stmt = $this->db->prepare('SELECT * FROM ' . $this->name);
            $stmt->execute();

            while ($row = $stmt->fetch(PDO::FETCH_NUM, PDO::FETCH_ORI_NEXT)) {
                $this->data[$row[0]] = $row[1];
            }
        }

        return $this->data;
    }

    /**
     * @param string $key key
     * @param string $value value
     *
     * @return string value stored
     * @throws InvalidArgumentException
     */
    public function set($key, $value, $email = null)
    {
        if (!is_string($key)) {
            throw new InvalidArgumentException('Expected string as key');
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Expected string as value');
        }

        $queryString = 'REPLACE INTO ' . $this->name . ' VALUES (:key, :value);';
        if ($email) {
            $queryString = 'REPLACE INTO ' . $this->name . ' VALUES (:key, :value, :email);';
        }
        $stmt = $this->db->prepare($queryString);
        $stmt->bindParam(':key', $key, PDO::PARAM_STR);
        $stmt->bindParam(':value', $value, PDO::PARAM_STR);
        if ($email) {
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        }
        $stmt->execute();
        $this->data[(string)$key] = $value;

        return $this->data[$key];
    }

    /**
     * @param string $key key
     *
     * @return null
     */
    public function delete($key)
    {
        $stmt = $this->db->prepare(
            'DELETE FROM ' . $this->name . ' WHERE ' . $this->keyColumnName
            . ' = :key;'
        );
        $stmt->bindParam(':key', $key, PDO::PARAM_STR);
        $stmt->execute();

        unset($this->data[$key]);
    }

    /**
     * Delete all values from store
     *
     * @return null
     */
    public function deleteAll()
    {
        $stmt = $this->db->prepare('DELETE FROM ' . $this->name);
        $stmt->execute();
        $this->data = array();
    }

    public function rewind() : void
    {
        $this->iterator = $this->db->query('SELECT * FROM ' . $this->name);
        $this->current = $this->iterator->fetch(PDO::FETCH_NUM, PDO::FETCH_ORI_NEXT);
    }

    public function next(): void
    {
        $this->current = $this->iterator->fetch(PDO::FETCH_NUM, PDO::FETCH_ORI_NEXT);
    }

    /**
     * Check if current position is valid
     */
    public function valid() : bool
    {
        return $this->current !== false;
    }

    public function current() : mixed
    {
        return isset($this->current[1]) ? $this->current[1] : null;
    }

    public function key() : mixed
    {
        return isset($this->current[0]) ? $this->current[0] : null;
    }

    public function count() : int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . $this->name)->fetchColumn();
    }
}
