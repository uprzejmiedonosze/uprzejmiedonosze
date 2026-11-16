# Akty prawne dla asystenta AI

Spis aktów: `index.json`. Treść aktu: `<id>.md`, gdzie **każdy artykuł to osobna sekcja `## Art. N`** (także `Art. 92a`).
Treść artykułu (ustępy, punkty) wpisz pod nagłówkiem bez zmian względem tekstu jednolitego.

```markdown
# Tytuł aktu (nagłówek dokumentu jest pomijany)

## Art. 49
1. Zabrania się zatrzymania pojazdu: …

## Art. 92a
…
```

Asystent NIE dostaje aktów w całości. Dwa narzędzia czytają stąd tylko to, co potrzebne do pytania:
`search_legal` (wyszukiwanie po treści, zwraca kilka artykułów) i `get_legal_provision` (konkretny artykuł).
Dzięki temu można tu trzymać całe ustawy i rozporządzenia bez wpływu na koszt zapytania.

Nowy akt: dopisz wpis do `index.json` (`id` = nazwa pliku, tylko `a-z0-9_-`) i dodaj plik `<id>.md`.
Przy aktualizacji tekstu jednolitego zmień `ref` (Dz.U.) — asystent przytacza go w odpowiedziach.
Brakujący plik lub akt bez artykułów jest po prostu pomijany (wyszukiwanie nic nie zwróci).
