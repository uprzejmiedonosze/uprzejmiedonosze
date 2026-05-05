-- Schema for telemetry database
-- Path: docker/db/telemetry.sqlite

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    event_name TEXT NOT NULL,
    user_id TEXT, -- SHA256 of user email
    session_id TEXT,
    app_id TEXT,
    data JSON
);

CREATE INDEX IF NOT EXISTS idx_events_timestamp ON events(timestamp);
CREATE INDEX IF NOT EXISTS idx_events_name ON events(event_name);

-- Optimization settings
PRAGMA journal_mode = WAL;
PRAGMA synchronous = OFF;
