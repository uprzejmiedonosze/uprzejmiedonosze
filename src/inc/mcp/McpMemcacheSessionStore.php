<?PHP

namespace mcp;

use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * MCP Streamable HTTP session store backed by memcached, via ext-memcache
 * like src/inc/store/Cache.php. Entries are keyed 'mcp-session:<uuid>' with a
 * TTL; memcached expires them, so gc() is a no-op.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final class McpMemcacheSessionStore implements SessionStoreInterface {
    private \Memcache $memcache;

    public function __construct(
        private readonly int $ttl = 3600,
        private readonly string $prefix = 'mcp-session:'
    ) {
        $this->memcache = new \Memcache();
        $this->memcache->connect(getenv('MEMCACHED_HOST') ?: 'localhost', 11211);
    }

    private function key(Uuid $id): string {
        return $this->prefix . $id->toRfc4122();
    }

    public function exists(Uuid $id): bool {
        return $this->memcache->get($this->key($id)) !== false;
    }

    public function read(Uuid $id): string|false {
        return $this->memcache->get($this->key($id));
    }

    public function write(Uuid $id, string $data): bool {
        return $this->memcache->set($this->key($id), $data, 0, $this->ttl);
    }

    public function destroy(Uuid $id): bool {
        return $this->memcache->delete($this->key($id));
    }

    /**
     * memcached expires entries by TTL on its own, so there is nothing to
     * sweep here.
     */
    public function gc(): array {
        return [];
    }
}
