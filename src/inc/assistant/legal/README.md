# Akty prawne dla asystenta AI

Spis aktów: `index.json`. Treść aktu: `<id>.md`, gdzie **każdy artykuł to osobna sekcja `## Art. N`** (także `Art. 92a`,
`Art. 60¹`), a w rozporządzeniach **każdy paragraf to `## § N`**. Sekcje `##` bez numeru (np. `## Rozdział 5 …`) tylko
rozdzielają tekst i są pomijane.

```markdown
# Tytuł aktu (nagłówek dokumentu jest pomijany)

## Rozdział 5 Zatrzymanie i postój pojazdu

## Art. 49
1. Zabrania się zatrzymania pojazdu:
  1) …
```

Asystent NIE dostaje aktów w całości. Dwa narzędzia czytają stąd tylko to, co potrzebne do pytania:
`search_legal` (wyszukiwanie po treści, zwraca kilka artykułów) i `get_legal_provision` (konkretny artykuł).
Dzięki temu można tu trzymać całe ustawy i rozporządzenia bez wpływu na koszt zapytania.
Artykuł dłuższy niż 3000 znaków (`Corpus.php`: `LONG_UNIT`) wyszukiwarka widzi jako osobne ustępy/punkty/paragrafy
(„Art. 2 pkt 29”, „Art. 130a ust. 5”, „Art. 96 § 1a”) z krótkim wstępem artykułu; `get_legal_provision` przyjmuje takie
odwołania, a dla całego długiego artykułu zwraca początek i spis części. Pliki `.md` się przez to nie zmieniają.

**Pliki `<id>.md` generuje skrypt — nie edytuj ich ręcznie:**

```bash
python3 tools/legal-acts.py check     # czy jest nowszy tekst jednolity, jakie nowelizacje wyszły po nim
python3 tools/legal-acts.py update    # pobierz najnowsze teksty jednolite z api.sejm.gov.pl (ELI)
```

Skrypt aktualizuje też `ref` (Dz.U. tekstu jednolitego, asystent przytacza go w odpowiedziach) i `url` w `index.json`.
Pełna procedura (nowy akt, weryfikacja wyniku, PDF bez wersji HTML): skill `legal-acts` w `.claude/skills/`.

Wyjątek: `taryfikator.md` (tabela B taryfikatora mandatów, pozycje `## Lp. N`) jest opracowany ręcznie z PDF
Dziennika Ustaw (`"manual": true` — skrypt go pomija); zmiany wprowadzaj ręcznie, patrz skill `legal-acts`.

Nowy akt: dopisz wpis do `index.json` (`id` = nazwa pliku, tylko `a-z0-9_-`; `eli` = adres aktu pierwotnego w ELI,
np. `DU/1997/602`; opcjonalnie `articles`, np. `"221-260"`, gdy potrzebny jest tylko jeden dział obszernego aktu)
i uruchom `update <id>`. Brakujący plik lub akt bez artykułów jest po prostu pomijany.
