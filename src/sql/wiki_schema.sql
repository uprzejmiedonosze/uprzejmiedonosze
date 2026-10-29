-- Wiki: osobna baza db/wiki.sqlite (src/inc/store/WikiStore.php), nie store.sqlite.
-- Najnowszy schemat (baza testowa: tests/bootstrap.php). Kolejne zmiany:
-- src/sql/wiki_migration_YYYYMMDD_<slug>.sql, każda podbija user_version.

-- Artykuły i notatki jednostek (path 'sluzby/<slug>'). path zapisywany raz; zmiana = nowy wpis w wiki_redirects.
-- route_alias: trasa www, która czyta treść z wiki (np. 'faq' dla /faq.html).
CREATE TABLE IF NOT EXISTS wiki_pages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    path        TEXT NOT NULL UNIQUE,
    title       TEXT NOT NULL,
    section     TEXT NOT NULL DEFAULT '',
    body_md     TEXT NOT NULL DEFAULT '',
    route_alias TEXT UNIQUE,
    status      TEXT NOT NULL DEFAULT 'published' CHECK (status IN ('published', 'draft', 'deleted')),
    current_rev INTEGER,
    updated_at  TEXT NOT NULL,
    updated_by  TEXT
);

-- Pełne snapshoty treści; diff liczony w PHP. Autor zapisany w chwili zapisu (brak JOIN-a z users).
CREATE TABLE IF NOT EXISTS wiki_revisions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id      INTEGER NOT NULL REFERENCES wiki_pages(id),
    title        TEXT NOT NULL,
    body_md      TEXT NOT NULL,
    summary      TEXT NOT NULL DEFAULT '',
    author_email TEXT,
    author_name  TEXT,
    created_at   TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS wiki_revisions_page ON wiki_revisions(page_id, id);
CREATE INDEX IF NOT EXISTS wiki_revisions_created ON wiki_revisions(created_at);
CREATE INDEX IF NOT EXISTS wiki_revisions_author ON wiki_revisions(author_email);

-- Zamrożone URL-e jednostek: slug generowany raz z nazwy, klucz konfiguracji to wewnętrzny id (ADR 0001).
-- Kilka kluczy jednego komisariatu może dzielić slug (Głuszyca, Ozimek, KMP Lublin).
CREATE TABLE IF NOT EXISTS wiki_unit_slugs (
    unit_type TEXT NOT NULL CHECK (unit_type IN ('sm', 'police')),
    unit_key  TEXT NOT NULL,
    slug      TEXT NOT NULL,
    PRIMARY KEY (unit_type, unit_key)
);
CREATE INDEX IF NOT EXISTS wiki_unit_slugs_slug ON wiki_unit_slugs(slug);

-- Media wiki to pliki w repo (src/img/wiki/ → /img/wiki/), dodawane przez admina commitem — bez tabeli i bez uploadu.

CREATE TABLE IF NOT EXISTS wiki_redirects (
    from_path  TEXT PRIMARY KEY,
    to_path    TEXT NOT NULL,
    created_at TEXT NOT NULL
);

-- Wyszukiwarka (/wiki/_search): tekst stron bez składni Markdown, aktualizowany przy zapisie (WikiStore::indexPage).
-- remove_diacritics 2: „zazalenie” znajduje „zażalenie”. Jednostki są szukane w konfiguracji, nie tutaj.
CREATE VIRTUAL TABLE IF NOT EXISTS wiki_fts USING fts5(
    path UNINDEXED,
    title,
    body,
    tokenize = 'unicode61 remove_diacritics 2'
);

PRAGMA user_version = 1;
