# 0007 — Gmina i powiat z Nominatim `zoom=10`, reszta adresu z `zoom=18`

**Status:** obowiązuje (2026-11-10)

## Kontekst
`\geo\Nominatim()` pytał `reverse` bez parametru `zoom` (domyślnie 18). Na tym poziomie Nominatim zwraca adres
**najbliższego obiektu** (budynek, sklep), nie gminę, w której leży punkt. Przy granicy gmin najbliższy obiekt może
stać po drugiej stronie: pinezka przy OBI w Bielanach Wrocławskich (gmina Kobierzyce) dawała Zabrodzie, gmina Kąty
Wrocławskie → zgłoszenie szło do niewłaściwego komisariatu. Granice w OSM są poprawne — wzorzec GUGiK (ULDK
`GetParcelByXY`) zgadzał się z `zoom=10`, nie z `zoom=18`.

Mapbox (napis adresu) i Nominatim (gmina/jednostka) są używane razem celowo: Mapbox daje czytelne adresy, Nominatim
dobrze wykrywa gminy. `zoom=10` **nie zastępuje** `zoom=18`: w miastach zwraca tylko `{city}` — bez dzielnicy
(`SM::guess` używa jej do oddziałów SM w Warszawie), bez kodu pocztowego i ulicy.

## Decyzja
- `Nominatim()` robi drugie zapytanie `zoom=10` (`NominatimAdmin()`, osobny klucz cache `"$lat,$lng,zoom10"`)
  i `withAdminBoundaries()` podmienia **tylko** `municipality` i `county` (gmina, powiat).
- Gdy `zoom=10` nic nie zwróci (miasta) albo zawiedzie — zostają wartości z `zoom=18`; błąd drugiego zapytania
  nigdy nie psuje odpowiedzi.

## Konsekwencje
- Dwa zapytania do Nominatim na punkt (limit 1 req/s; front i tak debounce'uje 1 s, odpowiedzi są cache'owane).
- Nie przechodź na samo `zoom=10` i nie wywołuj `reverse` bez `zoom` po gminę.
- Próbka 94 zgłoszeń z 2026-11-10: `zoom=18` zgadzał się z GUGiK we wszystkich, bo prawie wszystkie to duże miasta —
  błąd dotyka wąskiego pasa przy granicy gmin poza miastami.
