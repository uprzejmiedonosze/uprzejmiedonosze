# src/js/ — frontend (vanilla JS, ES modules, Parcel)

## Struktura

- **Entry bundles** (każdy `src/js/*.js` → `export/public/js/`): `index3.js` (cała witryna), `new-app.js` (formularz
  nowego zgłoszenia + `new-app/*`), `generator.js`, `firebase.js` (logowanie), `google-one-tap.js`, `sw.js`.
  Uwaga: kod formularza zgłoszenia (`new-app/validate-form.js` itd.) jest w **`new-app.js`**, nie w `index3.js`.
- `sites/<strona>.js` — logika jednej strony, importowana w `index3.js`. Wzorzec (patrz `sites/thank-you.js`):
  ```js
  document.addEventListener("DOMContentLoaded", () => {
    if (!document.getElementsByClassName('dziekujemy').length) return  // klasa = mainClass z renderHtml()
    ...
  })
  ```
  Nowa strona → nowy `sites/*.js` + `import` w `index3.js`. Nie twórz nowego entry bez potrzeby.
- `lib/` — współdzielone moduły (niżej). Zanim napiszesz helper, sprawdź tutaj.

## Katalog `lib/`

| Moduł | Do czego |
|---|---|
| `Api.js` | `new Api(url).getJson()` itd. — fetch do backendu z obsługą błędów (toast) i JSON. Używaj zamiast gołego `fetch` |
| `toast.js` | `toast(msg)`, `message(msg)`, `warning(msg)`, `error(msg)` — powiadomienia |
| `dialog.js` | `makeDialog()` — modalne `<dialog>` (inicjalizowane globalnie w `index3.js`) |
| `dropdown.js`, `tabs.js`, `sidebar.js` | Komponenty UI (`makeDropdown`, `makeTabs`) |
| `filterable.js` | `filterable(inputId, listId)` — filtrowanie listy po wpisanym tekście |
| `status.js` | `setStatus(appId, status)`, `updateStatus`, `updateCounters` — zmiana statusu zgłoszenia |
| `send.js` | `sendApplication(appId)`, `showButtons()` — wysyłka zgłoszenia |
| `validation.js` | `checkValue`, `checkValueRe`, `checkAddress`, `checkDateTimeValue`, `bindSoftCommentValidation` |
| `geolocation.js` | Mapa, `setAddressByLatLng`, wykrywanie jednostki SM, `setStopAgresji` |
| `format.js` | `num(value, numerals)` — polska odmiana liczebników (odpowiednik PHP `num()`) |
| `webauthn.js` | Passkeys: `supported`, `registerPasskey`, `loginWithPasskey`, `conditionalAvailable` |
| `firebaseConfig.js` | Konfiguracja Firebase per środowisko |
| `isIOS.js`, `delay.js`, `nodeListExt.js` | Drobne utilsy; `nodeListExt` dodaje m.in. `NodeList.prototype.addEventListener` |
| `sentry.js` | Inicjalizacja Sentry |

## Konwencje i pułapki

- Elementy obecne w DOM równocześnie w wersji mobile i desktop (CSS tylko przełącza widoczność): **nie używaj
  wspólnego `id`** — `getElementById` zwraca pierwszy. Używaj klasy `js-*` + `querySelectorAll(...).forEach`
  (wzór: `.js-new-app-submit`).
- Klasy `js-*` = hooki dla JS, nie stylujemy ich w SCSS.
- Typy w JSDoc (`/** @type {HTMLInputElement} */`), bez TypeScriptu.
- Lint: `tools/eslint.config.mjs`.

## Weryfikacja lokalna

`parcel watch` w kontenerze `builder` bywa zawodny na macOS — po zmianie wymuś build
(komenda w `services/AGENTS.md`, sekcja „Pułapki środowiska dev”) i potwierdź `grep`-em w `export/public/js/<entry>.js`.
E2E: `make cypress-local` / skill `cypress`.
