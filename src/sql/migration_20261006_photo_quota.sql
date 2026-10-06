-- Limit przetworzonych zdjęć (ALPR/LLM) na użytkownika w oknie PHOTO_QUOTA_DAYS (domyślnie 30 dni, kroczące).
-- Liczą się UNIKALNE zdjęcia (sha1 bajtów), więc ponowne wrzucenie tego samego pliku nie zużywa limitu.
-- Patrz src/inc/store/PhotoQuota.php.
CREATE TABLE IF NOT EXISTS photo_usage (
    user_email TEXT NOT NULL,
    sha1       TEXT NOT NULL,      -- sha1 bajtów zdjęcia (JPEG) wysłanych do dostawcy
    created_at INTEGER NOT NULL,   -- unix ts pierwszego przetworzenia w bieżącym oknie
    PRIMARY KEY (user_email, sha1)
);
CREATE INDEX IF NOT EXISTS photo_usage_user_ts ON photo_usage(user_email, created_at);
