# src/inc/ — backend PHP

Bootstrap: `include.php` (ładowany przez `src/index.php` i `src/api/rest/index.php`).
Przepływ: **Slim route → middleware → handler/closure → funkcja domenowa → store/integrations**.

## Warstwy i konwencje

| Katalog | Co | Konwencja |
|---|---|---|
| `handlers/` | Handlery tras webowych (`*Handler extends AbstractHandler`) | Cienkie: parsują request, wołają funkcję domenową, renderują. Nowy plik → `require` w `handlers/index.php` |
| `API.php` | **Wspólna logika domenowa** zgłoszeń (`setStatus`, `updateApplicationFields`, `finishApplication`, `assignPhoto`, `uploadImage`, `dashboardData`, `applicationToRest`…) | Jedna implementacja dla trzech wejść: handler webowy, REST (`src/api/rest/index.php`), MCP (`mcp/ReportMcpTools.php`). **Nie duplikuj logiki w handlerze/closure** |
| `store/` | Persystencja — **funkcje w namespace**, nie klasy: `\app\get/save`, `\user\get`, `\recydywa\…`, `\store\query/prepare`, `\cache\…`, `\semaphore\withLock`, `\queue\produce`, `\quota\…` | Nowy plik → `require` w `store/index.php`. SQL tylko tutaj |
| `dataclasses/` | Modele: `Application` (`\app`), `User` (`\user`), `SM`/`Police`/`StopAgresji`, `Category`, `Status`… | Dziedziczą po `JSONObject` (stdClass z JSON-a). Wyjątki domenowe w `Exceptions.php` |
| `middleware/` | Auth i content-type | Patrz niżej |
| `integrations/` | Zewnętrzne API: MailGun, Geolocation, ALPR (`plateRecognizer`, `openAlpr`), OpenAI/Vision, FaceDetect, CityAPI (Poznań), Tumblr | Klucz API zawsze z `getenv()` w `config.php` |
| `mcp/`, `oauth/`, `passkey/` | Serwer MCP, dostawca OAuth, WebAuthn | — |
| `converters/` | Eksport zgłoszeń: PDF (LaTeX), XLS, ZIP, DOC | — |

Konfiguracja domenowa ładowana raz w `config.php` jako globale: `$CATEGORIES`, `$SM_ADDRESSES`, `$POLICE_ADDRESSES`,
`$STATUSES`, `$EXTENSIONS`, `$STOP_AGRESJI`, `$LEVELS`, `$BADGES` (przez `\json\get()` z **exportu**, nie z `src/`).
Sekrety: `getenv()` → `define()` w `config.php`; nowy sekret dodaj tam i do `services/.env.*`.

## Middleware (Slim: **ostatni dodany wykonuje się pierwszy**)

- Web (`src/index.php`): `OptionalUserMiddleware` / `LoggedInMiddleware` / `RegisteredMiddleware` /
  `ModeratorMiddleware` / `AdminMiddleware` (sesja PHP, `SessionMiddleware.php`) + content-type
  (`HtmlMiddleware`, `JsonMiddleware`, `PdfMiddleware`…) + `JsonBodyParser`.
- REST (`src/api/rest/index.php`): `AuthMiddleware` (Firebase JWT) → `TokenSessionMiddleware` →
  `UserMiddleware` → `RegisteredMiddleware`/`TermsConfirmedMiddleware` (z `UserMiddleware.php`) → `AddStatsMiddleware`.
  Uwaga: dwie różne klasy `RegisteredMiddleware` — sesyjna i REST-owa.

## Błędy i logi

- Rzucaj wyjątki z `dataclasses/Exceptions.php` (`MissingParamException`, `ValidationException`, `ForbiddenException`,
  `NotSendableException`…) albo Slimowe `HttpException`; `JsonErrorRenderer`/`HtmlErrorRenderer` mapują je na kody HTTP.
- `AbstractHandler::getParam($params, 'x', $default)` — brak parametru bez defaultu = `MissingParamException`.
- Logi: tylko `log_debug` / `log_info($msg, $force)` / `log_error($msg, $e)` z `Logger.php` (ADR 0002).
- Sekcje krytyczne (wysyłka, webhooki): `\semaphore\withLock($key, $source, fn)`.

## Przepisy

**Nowa strona HTML**
1. Szablon `src/templates/<route>.html.twig` (patrz `src/templates/AGENTS.md`).
2. Metoda w `StaticPagesHandler` (prosta strona) lub w handlerze obszaru: `return $this->renderHtml($request, $response, '<route>', [...])`.
3. Route w `src/index.php` w odpowiedniej grupie (właściwy middleware dostępu). Wzór: `UserHandler::register`.

**Nowy endpoint JSON dla webu (sesja)** — grupa `/api` w `src/index.php`, metoda w `SessionApiHandler`,
`$this->renderJson($response, [...])`. Wzór: `SessionApiHandler::setStatus`.

**Nowy endpoint REST (mobile/MCP)** — `src/api/rest/index.php` + opis w `API.md`. Logikę umieść w `API.php`, jeśli może
się przydać webowi lub MCP. Szczegóły: `src/api/AGENTS.md`.

**Nowa tabela / kolumna**
1. Dopisz do `src/sql/base_schema.sql` (świeże instalacje, testy).
2. Dodaj `src/sql/migration_YYYYMMDD_<slug>.sql` z `IF NOT EXISTS` i komentarzem PL wskazującym plik store.
   Migracje **nie są aplikowane automatycznie** — podaj użytkownikowi komendę dla staging/prod.
3. Funkcje dostępu w nowym `store/<Name>.php` (`namespace <name>;`), `require` w `store/index.php`.
   Wzór: `store/PhotoQuota.php` + `tests/store/PhotoQuotaTest.php` (commit `c9afca79`).
4. Tabela wiki → osobna baza `db/wiki.sqlite` (`store/WikiStore.php`): `src/sql/wiki_schema.sql` (najnowszy schemat) + `wiki_migration_*.sql` podbijające `user_version` — skill `new-migration`.

**Wiki** ([ADR 0006](../../docs/adr/0006-wiki-in-ud.md)) — renderer `Wiki.php` (`\wiki\Renderer`: Markdown +
allowlistowane dyrektywy `DIRECTIVES` → partiale `templates/wiki/`, bloki `EMBEDS`), logika (uprawnienia, zapis,
jednostki, wyszukiwarka) też w `Wiki.php`, SQL w `store/WikiStore.php`, trasy w `handlers/WikiHandler.php`.
Redaktor nie pisze Twiga ani HTML. `Wiki.php` ładuj **po** `Twig.php` (używa `initBareTwig`). Nowa dyrektywa =
wpis w `DIRECTIVES` + partial + przykład w `\wiki\guide()`. Import z DokuWiki: `tools/wiki-import.php` + `WikiImport.php`.

**Asystent AI** ([ADR 0008](../../docs/adr/0008-chat-assistant.md)) — `assistant/` (`Assistant.php` pętla tool-calling i składanie promptu, `prompt.md` rola i zasady asystenta,
`Tools.php` narzędzia, `Corpus.php` wyszukiwanie), rozmowa w `store/ChatStore.php` (`\chat\`), trasy `/api/chat` w `handlers/ChatHandler.php`,
widżet: `templates/_chat-widget.html.twig` + `js/sites/chat.js` + `scss/lib/chat.scss`. Wiedza to pliki:
`assistant/knowledge/*.md` (`podstawy.md` zawsze w prompcie; `wiki-*.md` generuje `tools/wiki-knowledge.php` z wiki), `assistant/legal/` (akty, `## Art. N`), `assistant/skills/*.md`
(instrukcje, np. odwołanie — nowy skill = nowy plik). **Powiadomienie systemowe w czacie:** `\chat\notify($email, $tekst, '/app/list')`.
Załączniki (tylko patroni; obraz i PDF zużywają po kredycie `\quota`): `assistant/Attachments.php`.
Nowe narzędzie = wpis w `tools\definitions()` + gałąź w `tools\execute()` (tożsamość z `$user`, nigdy z argumentów modelu).
Test: `ClientFake` przez `assistant\ChatClient::set()`, korpus przez `assistant\corpus\setRoot()`.

**Nowa integracja zewnętrzna** — plik w `integrations/`, klucz przez `config.php`, odpowiedzi cache'uj w `\cache\…`
(wzór: `\cache\alpr`, `\cache\geo`), błędy przez `log_error` + wyjątek, nie `die`.

## Testy

`make test` (nigdy bezpośrednio phpunit). Testy z DB dziedziczą po `tests/DatabaseTestCase.php` (transakcja + rollback,
czysta `$_SESSION`). Układ katalogów `tests/` odpowiada obszarom (`API/`, `Dataclasses/`, `store/`, `mcp/`, `oauth/`…).

## Gotowe narzędzia — sprawdź, zanim napiszesz nowe

- `utils.php`: `trimstr2lower/upper`, `cleanWhiteChars`, `capitalizeName`, `capitalizeSentence`, `fixCapitalizedBrandNames`,
  `formatDateTime`, `num($n, $numerals)` (polska odmiana liczebników), `sexify($text, $sex)` (formy rodzajowe), `isIOS/isAndroid`,
  `getCorsOrigin`, `setSentryTag`.
- `Storage.php` (`\storage\`): `upload`, `download`, `ensure_local`/`release_local`, `exists`, `delete`, `cdnPrefix`.
- `Crypto.php` (`\crypto\encode/decode`), `FaceBlur.php`, `WipPhotos.php`, `LetterMatcher.php`/`LetterProposal.php`.
- `AbstractHandler`: `redirect`, `renderHtml`, `renderJson`, `renderPdf`, `renderJpeg`, `renderCsv`, `renderXls`, `renderDoc`, `getParam`.

## Decyzje do znania

- [ADR 0002](../../docs/adr/0002-logging-via-journald.md) logowanie ·
  [ADR 0003](../../docs/adr/0003-face-detection-pipeline.md) twarze (`FaceDetect.php`, `FaceBlur.php`) ·
  [ADR 0004](../../docs/adr/0004-mailgun-per-recipient-delivery.md) webhooki Mailguna (`WebhooksHandler.php`)
