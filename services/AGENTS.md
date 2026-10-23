# services/ — Docker, build, środowiska, logowanie

## Usługi (`services/compose.yml`)

| Service | dev | staging | prod | Role |
|---------|:---:|:-------:|:----:|------|
| `firebase-emulator` | ✓ | | | Firebase Auth emulator |
| `mailpit`, `firebase-mail-bridge` | ✓ | | | Lokalne przechwytywanie maili |
| `builder` | ✓ | | | Watches src/, runs parcel + poll loop (`webapp/watch.sh`) |
| `builder-test` | test | | | Jednorazowy kontener dla `make test` (bez `container_name`, działa obok `make dev`) |
| `webapp` | ✓ | | | nginx + PHP-FPM (code from builder volume) |
| `webapp-srv` | | ✓ | ✓ | nginx + PHP-FPM (code baked in image) |
| `memcached` | ✓ | ✓ | ✓ | Cache + jedyny magazyn sesji PHP (warm restart, patrz niżej) |
| `face-detect-consumer` | | ✓ | ✓ | PHP daemon — kolejka detekcji twarzy (ADR 0003) |
| `worker-cron` | | ✓ | ✓ | supercronic — cleanup, stats, s3-sync (`webapp/crontab`) |

Poza tym compose: Matomo (`matomo.nieradka.com`, zewnętrzny) oraz `/wiki` na prod — osobny projekt
`../wiki.uprzejmiedonosze` (DokuWiki) na wspólnej sieci Traefik `edge`, z routerem `PathPrefix(/wiki)`
o wyższym priorytecie niż `Host(uprzejmiedonosze.net)` z `webapp-srv`. Zobacz jego własny `CLAUDE.md`.

`memcached` to jedyny magazyn sesji PHP (`session.save_handler=memcached`). Działa z warm restartem
(`-e /data/memory_file` na wolumenie `memcached-data`, `stop_signal: SIGUSR1`), więc łagodny stop/recreate/restart
dockerd nie wylogowuje użytkowników; sesje traci tylko twardy kill (SIGKILL, OOM, crash hosta). Uszkodzony lub pusty
`memory_file.meta` (np. zapis przy pełnym dysku) powoduje segfault przy każdym starcie — komenda startowa w compose
go wtedy usuwa (marker `.loading`), więc memcached startuje pusty zamiast wpaść w pętlę restartów (każdy request = 500). Nie włączaj
`live-restore` w dockerd — kontenery nie byłyby wtedy łagodnie zatrzymywane przy wyłączaniu hosta. Strojenie locka sesji
(`memcached.sess_lock_wait_min/max`, w **milisekundach**) jest w `services/webapp/Dockerfile`.

Statystyki globalne (`src/inc/store/GlobalStats.php`: liczniki strony głównej, wykresy `/statystyki.html`) liczy
**wyłącznie** `worker-cron` (`tools/refresh-stats.php`: pełny przebieg 5:00 i 23:40, `--missing-only` co godzinę).
Request tylko czyta memcached — przy braku danych strona główna chowa liczby (+ ostrzeżenie w Sentry), a wykresy są
puste. staging i shadow nie mają `worker-cron`, więc nie mają tych statystyk.

## Środowiska

| | dev | staging | prod | shadow |
|---|---|---|---|---|
| Host | `localhost` | `staging.uprzejmiedonosze.net` | `uprzejmiedonosze.net` | `shadow.uprzejmiedonosze.net` |
| APP_ENV | `dev` | `staging` | `prod` | `prod` |
| webapp port | 80 | 8081 (localhost) | 8080 (localhost) | 18082 |
| Firebase | Emulator | Real | Real | Real |
| Sentry | off | off | on | jak prod |
| S3 | off | on | on | jak prod |
| CDN prefix | `cdn2` | `cdn2stg` | `cdn2` | `cdn2` |

**shadow** (od 2026-10-06): `/opt/uprzejmiedonosze-shadow` na hoście, pełny mirror prod (`APP_ENV=prod`,
**prawdziwa wysyłka maili**). Dane prod przez symlinki (`/var/www/shadow.uprzejmiedonosze.net/{db,cdn2,cdn}` →
`../uprzejmiedonosze.net/`) — `store.sqlite` (5.6 GB, WAL) jest produkcyjny, więc **wszystko na shadow
mutuje dane prod**. Tylko `webapp-srv` + `memcached` (worker dublowałby crony prod). Router Traefik
`ud-shadow` przez `TRAEFIK_ENV` w `.env.shadow`; `LOG_IDENT=shadow-webapp-srv`. Deploy: `make deploy-shadow`.

Obrazy/CDN: dev zapisuje do `/var/www/uprzejmiedonosze.net/cdn2/` w kontenerze (ginie przy restarcie — OK);
staging `cdn2stg/`, prod `cdn2/` na serwerze, synchronizowane do Backblaze B2. Prefiks: `\storage\cdnPrefix()`.

## Build (`webapp/Dockerfile`, `webapp/build.sh`, `webapp/watch.sh`)

- Etapy `builder`/`webapp`/`worker` dziedziczą ze wspólnego `php-base` (`debian:bookworm-slim` + Sury PHP
  + wspólne rozszerzenia) — dzięki temu BuildKit współdzieli cache. Nie wracaj do osobnych obrazów `node:*`/`nginx:*`.
- `webapp` instaluje `nginx` z repo nginx.org przypiętego `Pin-Priority` (Debianowe `nginx`/`nginx-light` = 1.22.1
  i konfliktują). `builder` instaluje Node przez NodeSource. Rozszerzenia per etap (`-cli`, `-fpm`, `-opcache`, `-memcached`).
- `build.sh` (staging/prod, w `docker build`): `config.env.php` → kopiowanie PHP/Twig/SQL/JSON (+ `tools/sm-parser.js`) →
  Parcel (CSS `src/scss/index3.scss`, JS `src/js/*.js`, własny namer bez hashy) → sitemap z `SITEMAP-PRIORITY` →
  `php -l` + twig-linter → fail2ban page → PHPUnit → `composer install --no-dev`.
- `watch.sh` (dev): pierwszy pełny build, potem `parcel watch` (CSS/JS) + pętla `find -newer` (PHP/Twig/SQL/JSON).

## Pułapki środowiska dev

- **Zmiany w `src/js/` często nie trafiają do bundla** — inotify nie przechodzi niezawodnie przez bind mount
  macOS→Linux. Wymuś przebudowę:
  `docker exec builder sh -c 'cd /build && node_modules/.bin/parcel build --dist-dir export/public/js/ src/js/<entry>.js'`
  i sprawdź `grep`-em w zbudowanym bundlu. PHP/Twig/JSON łapią się niezawodnie.
- `builder` bywa zatrzymany: `docker ps`, w razie potrzeby `docker start builder webapp`.
- `rm -rf export/` przy działającym `webapp` może zepsuć namespace montowania (404 wszędzie, „possible container
  breakout detected”). Restart nie pomaga, trzeba:
  `docker compose -f services/compose.yml -p uprzejmiedonosze-dev --profile dev up -d --force-recreate webapp builder`.
- Zalogowany użytkownik dev: `docker exec webapp sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite -init /var/www/uprzejmiedonosze.net/webapp/sql/init_registered.sql`,
  potem `curl -H "Cookie: UDSESSIONID=<wartość z cypress/support/commands.js>" http://localhost/app`.
- Weryfikacja zmian UI: `curl` na lokalny docker; wizualną weryfikację robi użytkownik.

## Logowanie

Szczegóły i uzasadnienie: [ADR 0002](../docs/adr/0002-logging-via-journald.md). W skrócie: `webapp-srv`,
`face-detect-consumer`, `worker-cron` montują `/run/systemd/journal`; `Logger.php` wysyła tam datagramy syslog
z poprawną severity i `LOG_IDENT`. nginx `error_log` tak samo (generowane przez `init.sh`), `access_log` na stdout.
Nie przełączaj logów z powrotem na stdout/stderr — `catch_workers_output` w `www.conf` scala oba strumienie.

## Serwery

- **Nigdy `sudo`** (także `sudo -n` przez SSH). Komendy wymagające roota albo dotykające prod storage
  (np. `rclone` na B2) — podaj komendę użytkownikowi, nie uruchamiaj sam.
- `make init-db-staging` kasuje dane staging.
