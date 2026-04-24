ALTER TABLE queue ADD COLUMN queue_name TEXT DEFAULT 'queue';
CREATE INDEX IF NOT EXISTS idx_queue_name ON queue(queue_name);
