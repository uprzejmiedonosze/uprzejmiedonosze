# 0001 — Klucze jednostek SM/Policji są niezmienne

**Status:** obowiązuje (supersedes ad hoc wzorce: Zielonka 2021, Murowana Goślina 2025)

## Kontekst
Zgłoszenie przechowuje tylko zlowercase'owany klucz z `sm.json`/`police.json` (`Application::smCity`).
Adres, e-mail i nazwa jednostki są rozwiązywane na żywo przy każdym renderze przez `SM::resolve()`.

Wcześniejsze podejścia psuły historię:
- Zielonka (2021) — nadpisanie wpisu w miejscu → utracone historyczne dane adresowe.
- Murowana Goślina (2025) — zmiana nazwy klucza → stare zgłoszenia przestały się rozwiązywać.

## Decyzja
- **Nigdy nie zmieniaj nazwy ani nie używaj ponownie klucza** istniejącej jednostki.
- Zlikwidowana jednostka: ustaw `"active": false` na istniejącym wpisie, resztę (klucz, adres, e-mail)
  zostaw bez zmian jako fakt historyczny; opcjonalnie dodaj `hint` z wyjaśnieniem.
- `SM::guess()` / `Police::guess()` pomijają `active === false`, więc nowe zgłoszenia spadają
  na kolejny poziom (miasto → gmina → powiat). `SM::resolve()` zwraca wpis bez zmian.
- Jeden klucz = jedna konkretna formacja. Gmina bez własnej SM → osobny klucz dla komisariatu Policji
  i mapowanie przez `parent`, bez ruszania wpisów SM dla miasta.

## Konsekwencje
- `sm.json` rośnie o martwe wpisy — to celowe.
- Testy w `tests/Dataclasses/SMTest.php` / `PoliceTest.php` powinny pokrywać każdy nowy przypadek `active: false`.
