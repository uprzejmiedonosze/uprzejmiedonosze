# 0005 — Zmiana URL-a strony

**Status:** obowiązuje (seria zmian `/app/*`, lipiec 2026)

## Kontekst
Strony zalogowanej części przeniesiono pod `/app/*` (np. `nowe-zgloszenie.html` → `/app/new`,
`potwierdz.html` → `/app/confirm`, `register.html` → `/app/account`). Stare URL-e są w mailach,
zakładkach i w aplikacji mobilnej.

## Decyzja
- **GET:** stary URL zostaje jako `302` na nowy.
- **POST (akcje):** przekierowanie nie przenosi metody ani body — zarejestruj route pod **starym i nowym**
  URL-em, wskazujące ten sam handler. Tylko GET-owe fallbacki (odświeżenie/podgląd) robią 302.
- Przy każdej zmianie: zaktualizuj **wszystkie** odwołania w `.twig`/`.js`/`.php` (grep), z wyjątkiem
  historycznych wpisów w `changelog.html.twig`.
- `/app/account` zostaje `/app/account` — użytkownik świadomie odrzucił zmianę na `/app/register`;
  nie „poprawiaj” bez pytania.

## Konsekwencje
- `src/index.php` zawiera pary route'ów stary/nowy — nie usuwaj starych jako „martwego kodu”.
