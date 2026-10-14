---
name: sm-unit
description: Dodaj, zaktualizuj lub oznacz jako zlikwidowaną jednostkę Straży Miejskiej/Gminnej albo komisariat Policji w sm.json / police.json. Use when the user asks to add a city guard or police unit, change its e-mail/address/hint, redirect a gmina to Police, or mark a unit as dissolved (zlikwidowana).
---

# Jednostka SM / Policji

Pliki: `src/api/config/sm.json` (Straż Miejska/Gminna), `src/api/config/police.json` (Policja).
Zasady i pipeline: `src/api/AGENTS.md`, decyzja o kluczach: `docs/adr/0001-sm-keys-immutable.md`. Przeczytaj oba przed zmianą.

## 1. Ustal fakty, zanim edytujesz

- Źródło: oficjalna strona/BIP gminy lub `*.policja.gov.pl`. Zapisz sobie URL — podasz go użytkownikowi.
- Sprawdź, jakie pola adresu dostaje aplikacja dla tego miejsca (współrzędne z mapy). Najpierw staging
  (pokazuje też przypisaną jednostkę):
  ```bash
  SID=$(grep -oE "^const SESSION_ID = '[^']+" cypress/support/commands.js | cut -d"'" -f2)
  curl -s -H "Cookie: UDSESSIONID=$SID" "https://staging.uprzejmiedonosze.net/api/geo/LAT,LON/n"
  ```
  HTTP 403 / strona „Wystąpił błąd” = sesja testowa na staging wygasła. Wtedy odpytaj Nominatim bezpośrednio:
  ```bash
  curl -s -A "uprzejmiedonosze-dev" "https://nominatim.openstreetmap.org/reverse?format=jsonv2&accept-language=pl&addressdetails=1&lat=LAT&lon=LON"
  ```
  i przemapuj jak `src/inc/integrations/Geolocation.php`: `city` = `city` ?? `town` ?? `village`;
  wartość zaczynająca się od „gmina …” → `county`, od „powiat …” → `municipality` (brak → „gmina/powiat <city>”).
  `SM::guess()` próbuje kolejno: kod pocztowy → `city` → `county` (także bez prefiksu „gmina ”) → `municipality`
  (powiat). Nowy klucz musi pasować do jednego z tych pól.
- Znajdź istniejący wpis: `grep -n -i '"<nazwa>' src/api/config/sm.json src/api/config/police.json`.

## 2. Wybierz właściwy przypadek

| Sytuacja | Co zrobić |
|---|---|
| Nowa SM w mieście/gminie | Nowy klucz = nazwa miejscowości/gminy jak w Nominatim (wielkość liter dowolna, sm-parser lowercase'uje) |
| Zmiana e-maila/adresu/hinta istniejącej jednostki | Edytuj wpis w miejscu. **Nie zmieniaj klucza** |
| Gmina bez SM → Policja | Osobny klucz komisariatu w `police.json` (np. „Komisariat Policji w X”) i gmina z `"parent": "<ten klucz>"`. Wpisów SM miasta nie ruszaj |
| Miejscowość obsługiwana przez SM sąsiedniej gminy | `"<miejscowość>": {"parent": "<klucz jednostki>"}` |
| SM zlikwidowana | `"active": false` na istniejącym wpisie + `hint` z wyjaśnieniem dokąd idą zgłoszenia. Klucz, adres, e-mail bez zmian. Jeśli nowa jednostka przejmuje teren — dodaj ją pod **innym** kluczem |

Pola wpisu: `address` (tablica linii: nazwa jednostki, ulica, kod + miasto), `email`, opcjonalnie `hint`
(praktyczne uwagi dla zgłaszającego, po polsku), `api` (domyślnie MailGun — nie ustawiaj bez powodu), `active`.
Trzymaj formatowanie i kolejność sąsiednich wpisów.

## 3. Weryfikacja

```bash
node tools/sm-parser.js src/api/config export/public/api/config   # rozwiąże parent, zwaliduje strukturę
make test                                                           # SMTest, PoliceTest i reszta
```
Dla nietrywialnego przypadku (nowy `active: false`, przekierowanie gminy) dopisz test w
`tests/Dataclasses/SMTest.php` lub `PoliceTest.php` — wzór: `testInactiveSMSkippedByGuessButKeptByResolve`,
`testGuessLeczycaNearPoznanGoesToKomorniki`.

## 4. Zakończenie

Podaj użytkownikowi: co zmieniłeś, źródło informacji (URL) i sugerowany wpis do changelogu w stylu istniejących,
np. „Zaktualizowano adres e-mail Straży Miejskiej we Władysławowie.” / „Dodano uwagi o współpracy z Komisariatem
Policji w Piastowie.”. Commit przez `/commit`.
