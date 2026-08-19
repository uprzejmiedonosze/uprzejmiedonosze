-- WebAuthn/passkey login. Additional login method for EXISTING accounts only;
-- the assertion is exchanged for a Firebase custom token, so /api/verify-token
-- stays the only endpoint that creates a session (AuthMiddleware/SessionApiHandler).

-- Opaque per-user handle sent to the authenticator as `user.id`. MUST be
-- random: the authenticator hands it back to the browser (and thus to the
-- server) on every assertion, while the Firebase uid is the passphrase for
-- the user's encrypted data (User::encode) and must never travel this way.
CREATE TABLE IF NOT EXISTS passkey_users (
    user_handle TEXT PRIMARY KEY,           -- base64url(32 random bytes)
    user_email  TEXT NOT NULL UNIQUE,
    created_at  TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS passkeys (
    credential_id TEXT PRIMARY KEY,         -- base64url of the raw credential id
    user_email    TEXT NOT NULL,            -- users.key
    user_id       TEXT NOT NULL,            -- Firebase uid at registration time (fallback only)
    public_key    TEXT NOT NULL,            -- PEM, as returned by lbuchs/webauthn
    sign_count    INTEGER NOT NULL DEFAULT 0,
    aaguid        TEXT,
    transports    TEXT,                     -- JSON array, e.g. ["internal","hybrid"]
    label         TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL,
    last_used_at  TEXT
);
CREATE INDEX IF NOT EXISTS passkeys_user_email ON passkeys(user_email);
