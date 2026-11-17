---
name: legal-acts
description: Odśwież lub dodaj akt prawny (ustawę, rozporządzenie) w bazie asystenta AI — src/inc/assistant/legal/ — z tekstu jednolitego w API Sejmu (ELI). Use when the user asks to update/refresh legal acts for the chat assistant, add a new act (e.g. rozporządzenie o znakach drogowych), or check whether the stored acts are current.
---

# Akty prawne asystenta AI

Pliki: `src/inc/assistant/legal/index.json` (spis) i `legal/<id>.md` (treść, każdy artykuł = `## Art. N`).
Format i zasady: `src/inc/assistant/legal/README.md`, decyzja: `docs/adr/0008-chat-assistant.md`.
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
`pdftotext -bbox-layout`: pomija nagłówki stron i przypisy (blok mniejszej czcionki na dole strony), akapity rozpoznaje
po odstępie pionowym (plus: tekst urwany na literze, przecinku lub skrócie „r.”/„poz.” to kontynuacja), indeks górny
(`[1]` albo mała cyfra przy liczbie) zamienia na `¹` (Art. 60¹ → klucz `60^1`, patrz `articleKey()` w `Corpus.php`).
Rozporządzenia (bez artykułów) dzieli na paragrafy `## § N`. Pole `articles` w `index.json` (np. `"221-260"`) zapisuje
tylko wybrane artykuły — tak jest z KPA (dział VIII, skargi i wnioski). Pole `exclude` pomija artykuły nieistotne dla
serwisu (rejestracja, prawa jazdy, e-myto…): `"64-70, 100a-100e, 140i"`. Litery w zakresie porównuje się jak w numeracji
ustaw (a < aa < at < b), koniec bez liter obejmuje wszystkie warianty (`72-80` → do 80zf). Numeracja przepisów jest stała
(uchylony artykuł nie zmienia znaczenia, nowy dostaje nowy numer), więc listy nie trzeba przeglądać przy odświeżaniu —
tylko gdy nowelizacja doda nowe artykuły w wyłączonym zakresie (np. 80zg). Skrypt wypisuje, ile jednostek wyłączył.
Wyłączenia wewnątrz artykułu w tym samym polu: `"2 pkt 87"`, `"130a ust. 5-7"`, `"12 ust. 1 pkt 3"`, `"2 pkt 2 lit. b"`,
`"§ 2 ust. 1 pkt 4"` — usuwa jednostkę z podpunktami (część wspólna po punktach zostaje). Ostatni poziom może być
zakresem. Numer pasujący w kilku ustępach („49 pkt 1”) jest odrzucany jako niejednoznaczny — podaj ustęp.
„NIE PASUJE” w wyniku = przepis zniknął lub zmienił numer: przejrzyj wpis.
Errata działa na pełnym tekście, przed `articles`/`exclude`.

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
   strony. Porównaj z PDF (`url` w `index.json` → ISAP). Błąd powtarzalny = zmiana w `pdf_to_md()`/`pdf_lines()`
   (sprawdź, że `git diff` innych aktów zawiera tylko poprawki). Pojedynczy przypadek, którego reguła nie rozstrzygnie
   (np. kontynuacja punktu po przecinku), albo błąd samego źródła (OCR w HTML z ISAP: „A-l 1” zamiast „A-11”) =
   reguła w `legal/errata.json` (`{from,to}` albo `{re,to}`). Skrypt wypisuje, ile razy zadziałała każda reguła;
   „NIE PASUJE” po odświeżeniu = źródło się zmieniło, przejrzyj i usuń/popraw regułę.
   Szukanie błędów OCR w kodach znaków: `grep -noE '\b[A-GPT]-(l|I)[0-9a-z]?\b|\b[A-GPT]- [0-9]' src/inc/assistant/legal/*.md`.
4. `make test` (testy `tests/assistant/CorpusTest.php` używają fixture'ów, ale ładują ten sam kod).

## 4. Nowy akt

1. Znajdź ELI aktu **pierwotnego** (nie obwieszczenia): `https://api.sejm.gov.pl/eli/acts/search?title=<fraza>&type=Ustawa`
   albo z adresu ISAP (`WDU19970980602` → `DU/1997/602`; pozycja = ostatnie cyfry bez zer wiodących). Dla aktów sprzed
   2012 r. ELI używa pozycji, nie numeru Dz.U. — sprawdź `curl -s https://api.sejm.gov.pl/eli/acts/DU/<rok>/<poz> | jq .title`.
2. Dopisz wpis do `index.json`: `id` (`a-z0-9_-`, krótki — model go podaje), `title`, `short`, `eli`, puste `ref`/`url`.
3. `python3 -I tools/legal-acts.py update <id>` i weryfikacja jak w kroku 3.
4. Akt bez tekstu jednolitego (np. świeże rozporządzenie) — skrypt go pominie. Do bazy trafiają tylko teksty
   jednolite; `--text <ELI samego aktu>` tylko na wyraźną prośbę użytkownika (tekst pierwotny bez późniejszych zmian).
5. Taryfikator mandatów (`legal/taryfikator.md`, `"manual": true` w `index.json`) jest opracowany ręcznie: tekst jednolity
   rozporządzenia `DU/2003/2023` jest z 2013 r., a tabelę B (wykroczenia drogowe) zastąpiło rozporządzenie `DU/2021/2484`
   (tabela tylko w PDF, ze scalonymi komórkami), zmienione `DU/2023/1256` (lp. 101a, 154a). Skrypt go pomija. Gdy wyjdzie
   nowelizacja taryfikatora (`check` dla `DU/2003/2023` jej nie pokaże — sprawdź „Akty zmieniające” w API), popraw/dopisz
   pozycje ręcznie w tym samym formacie: `## Lp. N` + linie Część / Naruszenie / Mandat karny (grzywna) / Kwalifikacja
   prawna / Naruszone przepisy; „X / Y” zł = stawka zwykła / w recydywie (art. 38 § 2 KW).

## 5. Zakończ

Commit przez `/commit` razem z `index.json`. W opisie podaj Dz.U. tekstów jednolitych i listę nieuwzględnionych
nowelizacji. Zmiany w `tools/legal-acts.py` lub `Corpus.php` → zaktualizuj ten skill i `legal/README.md`.
