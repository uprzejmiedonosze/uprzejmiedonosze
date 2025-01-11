
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
