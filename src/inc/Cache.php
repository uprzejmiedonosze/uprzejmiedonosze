<?PHP

class Cache {
    private \Memcache $cache;

    public function __construct() {
        $this->cache = new \Memcache;
        $this->cache->connect('localhost', 11211);
    }

    public function get(string $key) {
        return $this->cache->get("%HOST%-$key");
    }

    public function set(string $key, $value, $flag=0, $expire=24*60*60): void {
        $this->cache->set("%HOST%-$key", $value, $flag, $expire);
    }
    
    public function delete(string $key): void {
        $this->cache->delete("%HOST%-$key");
    }
}

$cache = new Cache();
