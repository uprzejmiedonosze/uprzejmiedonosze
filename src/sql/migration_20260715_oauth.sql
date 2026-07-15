-- OAuth 2.1 provider for the MCP server. Opaque tokens: only SHA-256 hashes
-- are stored, never the token itself. Apply with the other migrations
-- (sqlite3 db/store.sqlite < this-file).

-- uid -> email map. Report/user data is encrypted with the Firebase uid, but
-- user records are keyed by email, so a token must resolve both. Populated at
-- consent time.
CREATE TABLE IF NOT EXISTS oauth_users (
    user_id    TEXT PRIMARY KEY,           -- Firebase uid
    user_email TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

-- Dynamically-registered (DCR) or preconfigured MCP clients.
CREATE TABLE IF NOT EXISTS oauth_clients (
    client_id     TEXT PRIMARY KEY,
    name          TEXT NOT NULL,
    redirect_uris TEXT NOT NULL,            -- JSON array, matched exactly
    is_confidential INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL
);

-- Authorization codes. league carries the grant data inside the encrypted
-- code itself; we persist only the identifier for single-use + revocation.
CREATE TABLE IF NOT EXISTS oauth_auth_codes (
    id         TEXT PRIMARY KEY,
    revoked    INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NOT NULL
);

-- Opaque access tokens. All metadata lives here (there is no JWT to carry it);
-- the /mcp resource server validates by hashing the bearer and looking it up.
CREATE TABLE IF NOT EXISTS oauth_access_tokens (
    token_hash TEXT PRIMARY KEY,            -- sha256(opaque token)
    client_id  TEXT NOT NULL,
    user_id    TEXT,                        -- Firebase uid of the owner
    user_email TEXT,
    scopes     TEXT NOT NULL DEFAULT '',    -- space-separated
    resource   TEXT,                        -- audience (the /mcp resource URL)
    revoked    INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS oauth_access_tokens_client ON oauth_access_tokens(client_id);
CREATE INDEX IF NOT EXISTS oauth_access_tokens_user   ON oauth_access_tokens(user_id);

-- Refresh tokens. Rotated on use; revoking a connection revokes the family.
CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
    id              TEXT PRIMARY KEY,
    access_token_id TEXT,
    client_id       TEXT NOT NULL,
    user_id         TEXT,
    revoked         INTEGER NOT NULL DEFAULT 0,
    expires_at      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS oauth_refresh_tokens_user ON oauth_refresh_tokens(user_id);
