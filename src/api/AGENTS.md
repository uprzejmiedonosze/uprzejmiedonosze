# src/api/ — REST API i konfiguracja domenowa

## REST (`rest/index.php`)

- Klienci: aplikacja mobilna (`../uprzejmiedonosze-pro`, Expo), MCP, integracje. Kontrakt opisany w **`/API.md`** —
  każda zmiana endpointu/pola = aktualizacja `API.md` w tym samym commicie.
- Auth: Firebase JWT (`AuthMiddleware`) → `TokenSessionMiddleware` → `UserMiddleware` → `RegisteredMiddleware`/`TermsConfirmedMiddleware`.
- Odpowiedź zgłoszenia zawsze przez `applicationToRest()` (`src/inc/API.php`) — nie serializuj `Application` ręcznie.
- Nagłówki klienta: `X-UD-Client` (`mobilePlatform()`), `X-UD-Schema` (< `\vision\VISION_SCHEMA` → HTTP 426, wymuszenie aktualizacji).
- Logika domenowa w `src/inc/API.php`, nie w closure route'a (współdzielona z webem i MCP).

**Gałąź `feat/mobile`:** aplikacja mobilna działa tylko z backendem z tej gałęzi (`recipient` w odpowiedziach,
filtr `status=active` = wszystko poza archiwum i draftami, passkeys…). Staging ją uruchamia, lokalny `main` nie.
Testy kontraktowe mobile padające na lokalnym `main` to **nie bug** — nigdy nie zmieniaj `status=active` na `all`.
Zmiany tras REST na `main` konfliktują z `feat/mobile`: `/apps` musi zachować `appsQueryFromParams()` **i**
`array_map('applicationToRest', ...)`.

## Konfiguracja (`config/`)

PHP czyta **zbudowane** pliki z `export/public/api/config/`, nie źródła (`\json\get()` w `src/inc/config.php`).
`services/webapp/build.sh` przetwarza je w tej kolejności:

| Plik | Narzędzie | Co robi |
|---|---|---|
| `sm.json`, `police.json`, `stop-agresji.json` | `tools/sm-parser.js` | Rozwiązuje aliasy `parent` (pełna kopia danych rodzica + auto-`hint` „X obsługuje …”), **lowercase'uje klucze**, uzupełnia defaulty (`api: 'MailGun'`, `city`, `hint`) |
| `badges.json` | `tools/badges-validator.js` | Walidacja |
| `police-stations.csv` | `tools/police-stations.php` | → `police-stations.pjson` |
| `categories.json`, `statuses.json`, `extensions.json`, `levels.json` | kopiowane | Ładowane do klas `Category`, `Status`, `Extension`, `Level` |

### Edycja `sm.json` / `police.json`

- Klucze możesz pisać z polską wielkością liter — zostaną zlowercase'owane. `SM::guess()` dopasowuje po `trimstr2lower()`
  pól adresu z Nominatim. PHP nigdy nie obsługuje `->parent` sam.
- **Straż Miejska ma priorytet nad Policją.** Jeden klucz = jedna formacja. Gmina bez SM → osobny klucz
  „Komisariat Policji w …” i mapowanie gminy przez `parent`, bez ruszania wpisów SM dla miasta.
- **Zlikwidowana jednostka:** `"active": false` na istniejącym wpisie, nigdy zmiana nazwy/ponowne użycie klucza —
  [ADR 0001](../../docs/adr/0001-sm-keys-immutable.md).
- Sprawdzenie jednostki dla współrzędnych: `curl -s -H "Cookie: UDSESSIONID=<z cypress/support/commands.js>" "https://staging.uprzejmiedonosze.net/api/geo/LAT,LON/n"`.
- Po zmianie: `make test` (`tests/Dataclasses/SMTest.php`, `PoliceTest.php`).
