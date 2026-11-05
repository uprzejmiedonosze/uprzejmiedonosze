<?php

namespace UprzejmieDonosze\Tests\Store;

use PHPUnit\Framework\TestCase;

class QueueTest extends TestCase
{
    private string $path;
    private \SQLite3 $db;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/queue-test-' . uniqid() . '.sqlite';
        $this->db = \queue\open($this->path);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        foreach ([$this->path, "{$this->path}-wal", "{$this->path}-shm"] as $file)
            if (file_exists($file)) unlink($file);
    }

    public function testDequeueFromEmptyQueueReturnsNull(): void
    {
        $this->assertNull(\queue\dequeue($this->db));
    }

    public function testMessagesComeOutInFifoOrderAndOnlyOnce(): void
    {
        $this->assertTrue(\queue\enqueue($this->db, 'a'));
        $this->assertTrue(\queue\enqueue($this->db, 'b'));
        $this->assertTrue(\queue\enqueue($this->db, 'c'));

        $this->assertSame('a', \queue\dequeue($this->db));
        $this->assertSame('b', \queue\dequeue($this->db));
        $this->assertSame('c', \queue\dequeue($this->db));
        $this->assertNull(\queue\dequeue($this->db));
    }

    public function testConnectionWaitsForLocksInsteadOfFailingImmediately(): void
    {
        $this->assertSame(\queue\BUSY_TIMEOUT_MS, $this->db->querySingle('PRAGMA busy_timeout'));
    }

    public function testWorksInWalMode(): void
    {
        $this->assertSame('wal', strtolower($this->db->querySingle('PRAGMA journal_mode=WAL')));
        \queue\enqueue($this->db, 'x');

        $other = \queue\open($this->path);
        $this->assertSame('x', \queue\dequeue($other));
        $other->close();
    }
}
