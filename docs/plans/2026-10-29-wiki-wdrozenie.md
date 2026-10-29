# Wiki w UD — plan wdrożenia na prod i wyłączenia DokuWiki

Uzupełnia `2026-10-28-wiki-w-ud.md` (sekcje 4 i 6). Wszystkie komendy na `uprzejmiedonosze.net` uruchamia użytkownik.

Stan wyjściowy: `feat/wiki` zmergowany do `main`; na prod `/wiki` obsługuje kontener DokuWiki
(`../wiki.uprzejmiedonosze`, router Traefik `Host(uprzejmiedonosze.net) && PathPrefix(/wiki)`, priorytet 1000),
dane w `/opt/wiki/data` i `/opt/wiki/conf` (bind-mount). UD na prod ma już blok nginx `location ^~ /wiki`
— dostaje ruch dopiero, gdy router DokuWiki zniknie.

Przełączenie = **zatrzymanie kontenera DokuWiki**: Traefik usuwa router zatrzymanego kontenera, a `/wiki` przejmuje
router UD. Bez edycji `compose.yml` i bez restartu Traefika; rollback to `docker compose start wiki`.

## 0. Przed dniem wdrożenia

1. **Staging**: `make deploy-staging` z `main`, wgranie gotowej `wiki.sqlite` (jak w kroku 3 niżej, kontener
   `staging-webapp-srv-1`), akceptacja wizualna: `/wiki`, artykuł z obrazkami, `/wiki/sluzby` + wyszukiwarka,
   edycja z podglądem, historia/diff, `/faq.html`, `/przesluchanie.html`, `/jak-zglosic-nielegalne-parkowanie.html`.
2. **Przegląd slugów jednostek** (`docs/plans/2026-10-29-wiki-unit-slugs.txt`) — po wdrożeniu na prod URL-e
   jednostek są zamrożone.
3. `make test` zielone; changelog (29.10) uzupełniony.
4. Uprzedzenie 4 redaktorów DokuWiki o terminie zamrożenia.

## 1. Zamrożenie DokuWiki

Edycja off, odczyt dalej działa. `conf/local.php` nie jest nadpisywany przy starcie kontenera (entrypoint generuje
tylko `local-prod.php`, który nie ustawia `disableactions`). Przez kontener, bo `conf/` należy do jego `www-data`:

```bash
ssh uprzejmiedonosze.net "cd /opt/wiki && docker compose exec -T wiki sh -c \"cp conf/local.php conf/local.php.bak-2026-10 && echo \\\"\\\\\\\$conf['disableactions'] = 'edit,revert,media,register';\\\" >> conf/local.php && tail -1 conf/local.php\""
```

Jeśli cytowanie przez ssh sprawia kłopot: `ssh` na host, `docker compose exec wiki sh` i dopisać linię
`$conf['disableactions'] = 'edit,revert,media,register';` na końcu `conf/local.php` ręcznie.

Sprawdzenie: zalogowany redaktor nie widzi „Edytuj”.

## 2. Czy gotowa baza jest aktualna?

Gotowy plik (29.10, 13:25; 40 stron, 813 slugów jednostek):
`/private/tmp/claude-503/-Users-szn-dev-uprzejmiedonosze/f5fc5cc6-d8a3-44e8-ac1d-82ab068ad020/scratchpad/prod-wiki/wiki.sqlite`
— katalog tymczasowy, **skopiować w bezpieczne miejsce przed restartem maszyny**. Zbudowany z eksportu DokuWiki
z 29.10 rano i konfiguracji jednostek z `feat/wiki` (stan jak na `main` po merge'u).

```bash
ssh uprzejmiedonosze.net "find /opt/wiki/data/pages /opt/wiki/data/media -type f -newermt '2026-10-29 13:00' | head"
git log --oneline --since='2026-10-29 13:00' main -- src/api/config/sm.json src/api/config/police.json
```

Pusto w obu → bierzemy plik z 29.10. Inaczej przebudować (`wiki-import.php <dane> --db=<plik>`, dane jak w sekcji 6
planu, krok 2) i ponownie wygenerować listę slugów do przejrzenia.

## 3. Wgranie bazy na prod

`db/` jest bind-mountem z hosta (`services/compose.yml`: `${APP_DATA_ROOT}db:/var/www/uprzejmiedonosze.net/db`), więc
plik przeżyje odtworzenie kontenera przy deployu. Stary kod prod nie czyta `wiki.sqlite`; nowy po deployu od razu
pokaże w `faq`/`przesluchanie`/`jak-zglosic` treść z wiki (bez bazy — dotychczasową, nie błąd).
Shadow współdzieli ten katalog — od tej chwili to samo dotyczy shadow.

```bash
scp wiki.sqlite uprzejmiedonosze.net:/tmp/wiki.sqlite
ssh uprzejmiedonosze.net "docker cp /tmp/wiki.sqlite prod-webapp-srv-1:/var/www/uprzejmiedonosze.net/db/wiki.sqlite && docker exec prod-webapp-srv-1 chown www-data:www-data /var/www/uprzejmiedonosze.net/db/wiki.sqlite"
```

## 4. Próba na shadow (opcjonalnie, przed prod)

Shadow ma ten sam `db/`, a router DokuWiki pasuje tylko do hosta prod, więc na shadow `/wiki` = wiki UD na prodowej
bazie: `make deploy-shadow` (z `main`) i przejście po `/wiki`, `/wiki/sluzby`, kilku artykułach.
**Nie edytować na shadow** — zapis ląduje w prodowej bazie.

## 5. Deploy prod — i od razu przełączenie

**Od 29.10 `main` nie może trafić na prod bez przełączenia**: linki w stopce/menu (`config.php`) i przekierowania
`/epuap.html` itp. (`src/index.php`) prowadzą do nowych ścieżek wiki UD (`/wiki/wysylka-epuap`…). Pilna poprawka
przed przełączeniem → z brancha od `prod_main_2026-10-28`.

Między deployem a przełączeniem treść `faq`/`przesluchanie`/`jak-zglosic` (i przyciski „Edytuj”, „Historia”) linkuje
do nowych adresów `/wiki/<slug>`, których DokuWiki nie zna (404). Okno ma trwać minuty — krok 6 zaraz po sprawdzeniu.

```bash
make deploy-prod
```

Sprawdzenie: `/faq.html`, `/przesluchanie.html`, `/jak-zglosic-nielegalne-parkowanie.html` pokazują stopkę
„Treść tej strony redagują użytkownicy w wiki”; `/img/wiki/epuap_1-min.png` → 200.

## 6. Przełączenie

```bash
ssh uprzejmiedonosze.net "cd /opt/wiki && docker compose stop wiki"
```

(`/opt/wiki` = katalog projektu `wiki.uprzejmiedonosze` na hoście; `restart: unless-stopped` nie podniesie
ręcznie zatrzymanego kontenera po restarcie Dockera.)

Weryfikacja zaraz po (oczekiwane kody):

```bash
for u in /wiki /wiki/sluzby /wiki/_search?q=wroclaw \
         /wiki/niezbednik/wysylka_przez_epuap /wiki/doku.php?id=niezbednik:wysylka_przez_epuap \
         /wiki/Niezbednik:Wysylka_przez_ePUAP /wiki/_media/epuap_1-min.png \
         "/wiki/lib/exe/fetch.php?media=epuap_1-min.png" /epuap.html /przepisy.html; do
  printf '%-60s ' "$u"; curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' "https://uprzejmiedonosze.net$u"
done
```

`/wiki*` stare → 301 na nowy adres → 200; nowe → 200. Potem: 20–30 najczęściej odwiedzanych URL-i `/wiki` z GSC
(Strony, ostatnie 3 mies.) tą samą pętlą — każdy musi skończyć się 200 (`curl -sL -o /dev/null -w '%{http_code}'`).
Zalogowany z ≥1 zgłoszeniem: edycja i zapis testowej zmiany, potem cofnięcie przez moderatora.

Przez pierwsze dni: logi (`papertrail`, `filter=not-found /wiki`) — brakujące przekierowania dopisać do
`wiki_redirects`.

## 7. Rollback

```bash
ssh uprzejmiedonosze.net "cd /opt/wiki && docker compose start wiki"
```

DokuWiki wraca z zamrożonymi danymi; edycje zrobione w międzyczasie zostają w `wiki.sqlite` (do ponownego
przełączenia). Kod UD można zostawić; jedyny skutek uboczny to linki do nowych adresów `/wiki/<slug>` na `faq`/
`przesluchanie`/`jak-zglosic` (jak w kroku 5) — przy dłuższym rollbacku usunąć `db/wiki.sqlite` z prod
(po kopii!), wtedy te strony wracają do treści z szablonów.

## 8. Po wdrożeniu

- Następnego dnia: `wiki.sqlite` w logu nocnego `db-backup` i w buckecie.
- GSC: sprawdzić pokrycie po ~tygodniu (przekierowania zamiast 404).
- **Sprzątanie w repo** (po stabilnym przełączeniu, osobny commit):
  - fallback w `faq`/`przesluchanie`/`jak-zglosic-nielegalne-parkowanie.html.twig` (po akceptacji treści w wiki)
    i obrazki `src/img/how-*.jpg` używane tylko przez niego (zostają `mazak.jpg` — `naklejki-robisz-to-zle` — i `N.jpg`,
    ikony kategorii); wpisy `how-*` z `src/images-index.html` (build go tylko dopisuje — usunięty plik bez usunięcia
    wpisu wywala parcela);
  - strony wiki w sitemap.xml (dziś ich tam nie ma);
  - komentarze o DokuWiki: `services/AGENTS.md` (sekcja `/wiki`), `services/webapp/nginx.conf`, `src/index.php`.
- **Po ≥ 1 miesiącu bez rollbacku**:
  - `docker compose down` w `/opt/wiki` (bind-mount `/opt/wiki/data` zostaje);
  - archiwum `/opt/wiki/data` + `conf` do B2, potem usunięcie z hosta;
  - `POST /user/validate` (`SessionApiHandler::validateUser`) i `BACKEND_API_KEY` — jedyny znany klient to plugin
    `authud` DokuWiki; przed usunięciem potwierdzić w logach, że nikt inny go nie woła;
  - repo `wiki.uprzejmiedonosze` → archiwum.
