# 0010 — Limit Nominatim: przerwa w zapytaniach i adres zastępczy z MapBoxa

**Status:** obowiązuje (2026-11-26)

## Kontekst
Od 2026-11-19 publiczny `nominatim.openstreetmap.org` falami odrzuca zapytania z serwera (HTTP 429, potem 629,
strona błędu z CDN zamiast JSON). Wszyscy użytkownicy wychodzą z jednego IP. Formularz pokazywał wtedy błąd i nie
podpowiadał ani straży miejskiej, ani Policji.

## Decyzja
- Wszystkie zapytania idą przez `\geo\nominatimRequest()`. Po 429/629 albo odpowiedzi innej niż JSON przez 60 s
  (`NOMINATIM_COOLDOWN`) nic nie wysyłamy. Jeśli blokady 629 będą trwać godzinami, ten czas trzeba wydłużyć.
- Gdy zapytanie `reverse` zawiedzie, `Nominatim()` buduje adres z MapBoxa (`fromMapBox()`) i zwraca go tylko wtedy,
  gdy `SM::guess` znajdzie jednostkę. Inaczej zostaje pierwotny błąd — nie twierdzimy, że „nie powołano SM”.
- Adres zastępczy nie trafia do cache Nominatim.

## Konsekwencje
- Adres zastępczy nie ma gminy ani powiatu: przy granicy gmin podpowiedź może być mniej dokładna niż z ADR 0007,
  a na wsiach często nie ma jej wcale.
- MapBox nie podaje dzielnicy, więc w Warszawie (oddziały SM wg dzielnic) adres zastępczy nie działa.
- To łagodzi skutki. Limit usuwa dopiero własna instancja Nominatim albo płatny dostawca.
