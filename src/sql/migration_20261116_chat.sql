-- Asystent AI (czat na stronie): jedna ciągła rozmowa na użytkownika, która jest też skrzynką powiadomień systemowych.
-- Role: user / assistant / notification (wiadomość od systemu, wrzucana przez \chat\notify()).
-- „Nowa rozmowa” nie kasuje historii: ustawia archived_at na wiadomościach bieżącej rozmowy (\chat\archive()).
-- Patrz src/inc/store/ChatStore.php i src/inc/assistant/.
CREATE TABLE IF NOT EXISTS chat_messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_email TEXT NOT NULL,
    role       TEXT NOT NULL CHECK (role IN ('user', 'assistant', 'notification')),
    content    TEXT NOT NULL,
    meta       TEXT,               -- JSON: użyte narzędzia, flaga kontaktu, link powiadomienia…
    created_at INTEGER NOT NULL,
    read_at    INTEGER,            -- NULL = nieprzeczytane (tylko powiadomienia systemowe)
    archived_at INTEGER            -- NULL = bieżąca rozmowa; „Nowa rozmowa” archiwizuje (nie kasuje) poprzednią
);
CREATE INDEX IF NOT EXISTS chat_messages_user ON chat_messages(user_email, id);
