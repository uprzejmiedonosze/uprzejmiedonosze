# 0002 — Logi aplikacji przez datagramy syslog do journald

**Status:** obowiązuje (potwierdzone empirycznie na staging 2026-09-28)

## Kontekst
Chcieliśmy, żeby Papertrail dostawał poprawną severity (info/debug/error). Pierwsza próba:
pisać `log_info` na `php://stdout`, `log_error` na `php://stderr` i polegać na Dockerowym
`logging: driver: syslog`. **Nie działa:** `catch_workers_output = yes` w `services/webapp/www.conf`
scala stdout *i* stderr workerów w jeden `error_log` FPM-a (`/dev/stderr`) zanim Docker zobaczy
dwa strumienie — więc wszystko lądowało jako `ERROR`.

`catch_workers_output` nie da się wyłączyć: bez niego zabłąkane zapisy na stdout/stderr idą
do `/dev/null` (specyfikacja FastCGI).

## Decyzja
- `src/inc/Logger.php` wysyła każdą linię jako osobny datagram syslog
  (`stream_socket_client('udg://...')`) do zamontowanego z hosta `/run/systemd/journal`,
  z prawdziwym PRI (`facility<<3 | severity`) i identem `LOG_IDENT` (np. `staging-webapp-srv`).
- Mount dotyczy `webapp-srv`, `face-detect-consumer`, `worker-cron` (`services/compose.yml`).
- nginx `error_log` robi to samo (`syslog:server=unix:/run/systemd/journal/dev-log`),
  config generuje `init.sh` w `/etc/nginx/error_log.conf` zależnie od istnienia socketu.
  `access_log` zostaje na stdout.
- Brak socketu (dev/test) → fallback na `error_log(..., 'php://stderr')`.
- Własny log php-fpm (`php-fpm.conf`: start/stop mastera, respawny) zostaje na `/dev/stderr` —
  to faktycznie zdarzenia poziomu error.

## Konsekwencje
- W kodzie aplikacji używaj wyłącznie `log_debug` / `log_info` / `log_error`, nigdy `error_log`/`echo`.
- Nie próbuj „uprościć” tego z powrotem do stdout/stderr.
