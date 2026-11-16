---
name: legal-acts
description: Odśwież lub dodaj akt prawny (ustawę, rozporządzenie) w bazie asystenta AI — src/inc/assistant/legal/ — z tekstu jednolitego w API Sejmu (ELI). Use when the user asks to update/refresh legal acts for the chat assistant, add a new act (e.g. rozporządzenie o znakach drogowych), or check whether the stored acts are current.
---

# Akty prawne asystenta AI

Pliki: `src/inc/assistant/legal/index.json` (spis) i `legal/<id>.md` (treść, każdy artykuł = `## Art. N`).
Format i zasady: `src/inc/assistant/legal/README.md`, decyzja: `docs/adr/0007-chat-assistant.md`.
Narzędzie: `tools/legal-acts.py` (stdlib Pythona; PDF przez `pdftotext` z poppler). **`<id>.md` nie edytuj ręcznie** —
każda poprawka idzie do skryptu, inaczej zniknie przy następnym odświeżeniu.

## 1. Sprawdź stan

```bash
python3 -I tools/legal-acts.py check
```

Dla każdego aktu: obecny `ref`, najnowszy tekst jednolity w API i nowelizacje ogłoszone **po** nim.
Tekst jednolity nie zawiera późniejszych nowelizacji — skrypt dopisuje to do `ref` („późniejsze nowelizacje
nieuwzględnione”) i do nagłówka `<id>.md` (lista Dz.U.), a asystent widzi `ref` w prompcie. Nie da się tego obejść
automatycznie: ustawy zmieniające opisują zmiany („w art. 49 ust. 1 pkt 2 otrzymuje brzmienie…”), nie pełne teksty.
Jeśli nowelizacja dotyczy przepisów ważnych dla serwisu (np. art. 49 PoRD, art. 92/97 KW), powiedz o tym użytkownikowi.

## 2. Odśwież

```bash
python3 -I tools/legal-acts.py update            # wszystkie
python3 -I tools/legal-acts.py update kw         # jeden akt
python3 -I tools/legal-acts.py update kw --text DU/2026/1372   # wymuszony tekst jednolity (ELI obwieszczenia)
```

Skrypt bierze treść z `text.html` (API Sejmu ma ją dla części obwieszczeń). Gdy jest tylko PDF, parsuje
`pdftotext -bbox-layout`: pomija nagłówki stron i przypisy (mniejsza czcionka), akapity rozpoznaje po odstępie
pionowym, indeks górny `[1]` zamienia na `¹` (Art. 60¹ → klucz `60^1`, patrz `articleKey()` w `Corpus.php`).

## 3. Zweryfikuj wynik — zawsze, szczególnie dla PDF

1. Wypisana liczba artykułów jest sensowna i **nie ma „DUPLIKATY numerów”** (duplikat = `get_legal_provision`
   zwróci zły artykuł; zwykle oznacza, że do treści wpadł fragment obwieszczenia).
2. `git diff --stat src/inc/assistant/legal/` — przy odświeżeniu zmiany powinny być punktowe; przepisanie całego
   pliku oznacza zmianę formatu źródła → przejrzyj wynik dokładnie.
3. Obejrzyj kilka artykułów, w tym z punktami i częścią wspólną po punktach:
   ```bash
   awk -v s="## Art. 49" 'f&&/^## /{exit} $0==s{f=1} f' src/inc/assistant/legal/prd.md
   awk -v s="## Art. 97a" 'f&&/^## /{exit} $0==s{f=1} f' src/inc/assistant/legal/kw.md
   ```
   Typowe błędy PDF: „podlega karze…” doklejone do ostatniego punktu, przypis w treści, urwany akapit na granicy
   strony. Porównaj z PDF (`url` w `index.json` → ISAP). Poprawka = zmiana w `pdf_to_md()`/`pdf_lines()`.
4. `make test` (testy `tests/assistant/CorpusTest.php` używają fixture'ów, ale ładują ten sam kod).

## 4. Nowy akt

1. Znajdź ELI aktu **pierwotnego** (nie obwieszczenia): `https://api.sejm.gov.pl/eli/acts/search?title=<fraza>&type=Ustawa`
   albo z adresu ISAP (`WDU19970980602` → `DU/1997/602`; pozycja = ostatnie cyfry bez zer wiodących). Dla aktów sprzed
   2012 r. ELI używa pozycji, nie numeru Dz.U. — sprawdź `curl -s https://api.sejm.gov.pl/eli/acts/DU/<rok>/<poz> | jq .title`.
2. Dopisz wpis do `index.json`: `id` (`a-z0-9_-`, krótki — model go podaje), `title`, `short`, `eli`, puste `ref`/`url`.
3. `python3 -I tools/legal-acts.py update <id>` i weryfikacja jak w kroku 3.
4. Akt bez tekstu jednolitego (np. świeże rozporządzenie) — skrypt go pominie; użyj `--text <ELI samego aktu>`.

## 5. Zakończ

Commit przez `/commit` razem z `index.json`. W opisie podaj Dz.U. tekstów jednolitych i listę nieuwzględnionych
nowelizacji. Zmiany w `tools/legal-acts.py` lub `Corpus.php` → zaktualizuj ten skill i `legal/README.md`.
