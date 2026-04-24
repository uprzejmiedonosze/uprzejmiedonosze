<?php namespace queue;

use PDO;

class SimpleQueue {
    private PDO $pdo;
    private string $name;

    public function __construct(string $name) {
        $this->name = $name;
        $queueFile = dirname(DB_FILENAME) . '/queue.sqlite';
        
        $this->pdo = new PDO('sqlite:' . $queueFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Initial creation
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT, 
            queue_name TEXT DEFAULT 'queue',
            data TEXT
        );");
        
        // Migration for existing tables
        try {
            // Check if column exists
            $stmt = $this->pdo->query("PRAGMA table_info(queue)");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('queue_name', $columns)) {
                $this->pdo->exec("ALTER TABLE queue ADD COLUMN queue_name TEXT DEFAULT 'queue'");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_queue_name ON queue(queue_name)");
            }
        } catch (\Exception $e) {
            // Log or ignore if already exists/failed
        }
    }

    public function enqueue(string $data): bool {
        $stmt = $this->pdo->prepare("INSERT INTO queue (queue_name, data) VALUES (?, ?)");
        return $stmt->execute([$this->name, $data]);
    }

    public function dequeue(): ?string {
        // SQLite TRANSACTION for atomicity
        $this->pdo->exec("BEGIN IMMEDIATE TRANSACTION");
        try {
            $stmt = $this->pdo->prepare("SELECT id, data FROM queue WHERE queue_name = ? ORDER BY id ASC LIMIT 1");
            $stmt->execute([$this->name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                $del = $this->pdo->prepare("DELETE FROM queue WHERE id = ?");
                $del->execute([$row['id']]);
                $this->pdo->exec("COMMIT");
                return $row['data'];
            }
            
            $this->pdo->exec("ROLLBACK");
            return null;
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec("ROLLBACK");
            }
            throw $e;
        }
    }

    public function listen(callable $fn, int $delay = 5): void {
        while (true) {
            try {
                $item = $this->dequeue();
                if ($item !== null) {
                    $fn($item);
                } else {
                    sleep($delay);
                }
            } catch (\Exception $e) {
                logger("Queue Error ({$this->name}): " . $e->getMessage(), true);
                sleep($delay);
            }
        }
    }
}

$queues = [];

function get(string $name): SimpleQueue {
    global $queues;
    if (!isset($queues[$name])) {
        $queues[$name] = new SimpleQueue($name);
    }
    return $queues[$name];
}

function produce(string $msg, string $queueName = 'queue'): bool {
    return \queue\get($queueName)->enqueue($msg);
}

function consume(callable $fn, string $queueName = 'queue'): void {
    \queue\get($queueName)->listen($fn);
}
