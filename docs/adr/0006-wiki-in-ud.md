# 0006 — Wiki w UD: Markdown + allowlistowane dyrektywy, osobna baza, media z repo

**Status:** obowiązuje (od 2026-10, branch `feat/wiki`; zastępuje DokuWiki pod `/wiki`)

## Kontekst
DokuWiki pod `/wiki` odstawała wizualnie od UD i nie pozwalała przenieść stron www (FAQ, „Jak zgłosić”,
„Przesłuchanie”). Edycja jest otwarta dla użytkowników (każdy z co najmniej jednym wysłanym zgłoszeniem),
więc treść jest niezaufana. Plan i audyt: `docs/plans/2026-10-28-wiki-w-ud.md`.

## Decyzja
- **Redaktor nigdy nie pisze Twiga ani HTML.** `src/inc/Wiki.php`: league/commonmark z `html_input: strip`,
  `allow_unsafe_links: false`, atrybut `{#…}` tylko `id`. Elementy specjalne to **dyrektywy z allowlisty**
  (`DIRECTIVES` → stały partial `templates/wiki/_directive-*`, atrybuty walidowane typem) i **bloki osadzane**
  (`EMBEDS`, dane dostarcza kod). Nazwa spoza listy = zwykły tekst + błąd w podglądzie; zapis z błędami odrzucany.
- **Mermaid** z `securityLevel: 'strict'` (etykiety to treść redaktora); linki zewnętrzne `rel="nofollow ugc"`;
  historia/edycja `noindex`.
- **Osobna baza `db/wiki.sqlite`** (treść publiczna oddzielona od danych zgłoszeń; backup przez glob `db/*.sqlite`).
  Schemat stosowany przez `\wiki\db()` przy `user_version = 0`, zmiany — `wiki_migration_*.sql` (skill `new-migration`).
- **Media to pliki w repo** (`src/img/wiki/`), dodawane przez admina commitem — bez uploadu i bez tabeli.
- **Jednostki (`/wiki/sluzby`) z konfiguracji**, nie z treści: karta z `sm.json`/`police.json`, edytowalna jest tylko
  notatka (`wiki_pages` `sluzby/<slug>`). Slug zamrażany przy pierwszym użyciu (`wiki_unit_slugs`) — URL nie zmienia
  się przy zmianie nazwy w konfiguracji (ADR 0001: klucz to wewnętrzny id).
- **Strony www edytowane w wiki** (`\wiki\WWW_PAGES`) zostają pod starym adresem; szablon pokazuje treść z wiki,
  a bez niej dotychczasową (fallback do czasu usunięcia).

## Konsekwencje
- Nowy element strony = nowa dyrektywa/blok w `Wiki.php` + partial + przykład w `\wiki\guide()` (test pilnuje,
  że przykłady poradnika się renderują). Nie dodawaj „trybu HTML” dla redaktorów.
- Zmiana reguł slugów (`unitSlugBase`) po pierwszym deployu nie zmienia istniejących URL-i — tylko nowych jednostek.
- Shadow współdzieli `db/` z prod: shadow z wiki = edycje na prod (`services/AGENTS.md`).
