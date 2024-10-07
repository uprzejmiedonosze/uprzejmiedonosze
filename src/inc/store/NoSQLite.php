<?php namespace store;

require(__DIR__ . '/Store.php');

/**
 * Simple key => value store based on SQLite3
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class NoSQLite
{
    /**
     * @var PDO
     */
    public $db = null;

    /**
     * @param string $filename datastore file path
     */
    public function __construct($filename)
    {
        $this->db = new \PDO('sqlite:' . $filename);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param string $storeName store name
     * @return \NoSQLite\Store
     */
    public function getStore($storeName)
    {
        $store = new Store($this->db, $storeName);
        return $store;
    }
}
