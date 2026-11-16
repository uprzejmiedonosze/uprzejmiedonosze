
CREATE TABLE IF NOT EXISTS applications (
    "key" TEXT PRIMARY KEY,
    "value" TEXT,
    email VARCHAR
);

CREATE INDEX IF NOT EXISTS application_email
ON applications(email);

CREATE TABLE IF NOT EXISTS users (
    "key" TEXT PRIMARY KEY,
    "value" TEXT
);
CREATE TABLE IF NOT EXISTS recydywa (
    "key" TEXT PRIMARY KEY,
    "value" TEXT
);

CREATE TABLE IF NOT EXISTS webhooks (
    "key" TEXT PRIMARY KEY,
    "value" TEXT
);

CREATE TABLE IF NOT EXISTS photo_usage (
    user_email TEXT NOT NULL,
    sha1       TEXT NOT NULL,      -- sha1 bajtów zdjęcia (JPEG) wysłanych do dostawcy
    created_at INTEGER NOT NULL,   -- unix ts pierwszego przetworzenia w bieżącym oknie
    PRIMARY KEY (user_email, sha1)
);
CREATE INDEX IF NOT EXISTS photo_usage_user_ts ON photo_usage(user_email, created_at);

CREATE TABLE IF NOT EXISTS chat_messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_email TEXT NOT NULL,
    role       TEXT NOT NULL CHECK (role IN ('user', 'assistant', 'notification')),
    content    TEXT NOT NULL,
    meta       TEXT,               -- JSON: użyte narzędzia, flaga kontaktu, link powiadomienia…
    created_at INTEGER NOT NULL,
    read_at    INTEGER             -- NULL = nieprzeczytane (tylko powiadomienia systemowe)
);
CREATE INDEX IF NOT EXISTS chat_messages_user ON chat_messages(user_email, id);
