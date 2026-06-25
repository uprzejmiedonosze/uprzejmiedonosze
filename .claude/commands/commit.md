---
description: Uruchom testy, zrób commit, a na branchu main uzupełnij changelog i wykonaj push
allowed-tools: Bash(date:*), Bash(make:*), Bash(git status:*), Bash(git diff:*), Bash(git add:*), Bash(git commit:*), Bash(git push:*), Bash(git log:*), Bash(git branch:*), Bash(head:*), Read, Edit
---

## Kontekst

- Dzisiejsza data: !`date '+%A, %-d %B %Y' | LC_TIME=pl_PL.UTF-8 date -f - '+%A, %-d %B %Y' 2>/dev/null || LC_ALL=pl_PL.UTF-8 date '+%A, %-d %B %Y'`
- Aktualny branch: !`git branch --show-current`
- Status repozytorium: !`git status`
- Staged diff: !`git diff --staged`
- Unstaged diff (jeśli nic nie jest staged): !`git diff`
- Górna część changelogu (pierwsze 30 linii po nagłówkach): !`head -40 src/templates/changelog.html.twig`

## Twoje zadanie

Wykonaj poniższe kroki **w tej kolejności**. Zatrzymaj się i zgłoś błąd, jeśli którykolwiek krok się nie powiedzie.

### Krok 1 — Testy

Uruchom `make test`. Jeśli testy nie przejdą, zatrzymaj się i opisz błąd. Nie wykonuj kolejnych kroków.

### Krok 2 — Ustal zakres zmian i branch

Sprawdź `git status`:
- Jeśli są staged pliki → commit obejmuje wyłącznie staged pliki (użyj `git diff --staged` do analizy zmian).
- Jeśli nic nie jest staged → commit obejmie wszystkie zmienione/nieśledzone pliki (użyj `git diff` do analizy zmian). W takim razie przed commitem zastosuj `git add` dla wszystkich zmienionych plików.

Sprawdź aktualny branch (`git branch --show-current`):
- Jeśli to **`main`** → wykonaj Krok 3 (changelog) i Krok 5 (push).
- Jeśli to **inny branch** → **pomiń Krok 3 i Krok 5** w całości: nie zmieniaj `changelog.html.twig` i nie wykonuj `git push`. Commit zostaje lokalnie na branchu.

Nie commituj pliku `src/templates/changelog.html.twig` osobno — gdy go aktualizujesz (czyli na `main`), trafia do tego samego commita co pozostałe zmiany.

### Krok 3 — Changelog (tylko na branchu `main`)

Przeczytaj `src/templates/changelog.html.twig` i zaktualizuj go:

- Określ dzisiejszą datę w formacie polskim, np. „Niedziela, 13 kwietnia 2026".
- Jeśli na początku pliku (za nagłówkami `{% ... %}`) istnieje już sekcja `<h4>` z dzisiejszą datą → **dopisz nowy `<li>`** do istniejącej listy `<ul>`.
- Jeśli nie ma sekcji z dzisiejszą datą → **wstaw nową sekcję** `<h4>…</h4><ul><li>…</li></ul>` bezpośrednio po ostatnim znaczniku `<h2>` lub na początku treści, przed istniejącymi sekcjami.

Treść wpisu: krótki opis po polsku tego, co zmieniają pliki objęte commitem. Styl: zwięzły, jak w istniejących wpisach. Nie wymieniaj nazw plików wprost – opisz funkcjonalną zmianę.

### Krok 4 — Commit

Zrób commit (staged pliki + `src/templates/changelog.html.twig`, jeśli był aktualizowany w Kroku 3). Opis commita po angielsku, zgodny z konwencją obecną w `git log --oneline -10`.

### Krok 5 — Push (tylko na branchu `main`)

Jeśli jesteś na `main`, wykonaj `git push`. Na innym branchu nie wykonuj push — zakończ na commicie.
