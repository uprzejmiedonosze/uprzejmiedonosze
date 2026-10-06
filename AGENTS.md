# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Uprzejmie Donosze** ("Politely Report") — a Polish civic reporting platform for citizens to file parking violations and traffic safety complaints to appropriate authorities (city guards, police departments).

Stack: PHP 8.4 (Slim 4), Twig templates, Vanilla JS (ES modules), SCSS, SQLite, Firebase Auth, Parcel 2 bundler, Docker/nginx.

## Commands

```bash
# Local development (all build steps run inside Docker)
make dev                # docker compose --profile dev up --build (full dev stack)
make emulator-ui        # Open Firebase emulator UI at http://localhost:4000
make init-db-dev        # Initialize dev SQLite database (run once)

# Tests
make test               # Run PHPUnit inside the webapp container
make cypress-local      # Run Cypress E2E tests against local dev environment

# Release
make sentry-release     # Tag prod release + upload JS source maps to Sentry
make log-from-last-prod # Commits since last production release
make diff-from-last-prod # Diff since last production release

# Cleanup
make clean              # Remove export/, .parcel-cache/
make init-db-staging    # Initialize staging SQLite database (via SSH)
```

**Docker Compose — direct commands:**
```bash
# Dev
docker compose -f services/compose.yml --env-file services/.env.dev -p dev --profile dev up --build

# Staging (on server)
docker compose -f services/compose.yml --env-file services/.env.staging -p staging --profile staging up --build -d

# Prod (on server)
docker compose -f services/compose.yml --env-file services/.env.prod -p prod --profile prod up --build -d
```

**Running a single PHPUnit test:**
```bash
docker exec webapp ./vendor/phpunit/phpunit/phpunit --filter TestClassName tests/
```

**Running a single Cypress test:**
```bash
CYPRESS_BASE_URL=http://127.0.0.1 ./node_modules/.bin/cypress run --spec "cypress/e2e/your-test.cy.js" --e2e --env DOCKER=1
```

## Architecture

### Docker Services

All services are defined in `services/compose.yml` with three profiles:

| Service | dev | staging | prod | Role |
|---------|:---:|:-------:|:----:|------|
| `firebase-emulator` | ✓ | | | Firebase Auth emulator |
| `builder` | ✓ | | | Watches src/, runs parcel + inotifywait |
| `webapp` | ✓ | | | nginx + PHP-FPM (code from builder volume) |
| `webapp-srv` | | ✓ | ✓ | nginx + PHP-FPM (code baked in image) |
| `memcached` | ✓ | ✓ | ✓ | Cache |
| `face-detector` | | ✓ | ✓ | Python face detection API |
| `face-detect-consumer` | | ✓ | ✓ | PHP daemon — processes face detect queue |
| `worker-cron` | | ✓ | ✓ | supercronic — cleanup, stats, s3-sync |

Analytics is tracked via an external, centrally-hosted Matomo instance (`matomo.nieradka.com`), not a service in this compose file.

`/wiki` on prod is a separate project, `../wiki.uprzejmiedonosze` (a DokuWiki fork), not a service in this compose file either — it's its own container on the shared `edge` Traefik network on the same host, routed by a higher-priority `PathPrefix(/wiki)` router so it wins over `webapp-srv`'s `Host(uprzejmiedonosze.net)` router for that path. See its own `CLAUDE.md`.

### Build Pipeline

All source lives in `src/`, built artifacts go to `export/` (never edit export directly).

**Dev**: The `builder` container mounts `src/` read-only and `export/` read-write. `watch.sh` runs an initial build then watches for file changes via `inotifywait` (PHP/Twig/JSON) and `parcel watch` (CSS/JS).

**Staging/prod**: `build.sh` runs inside the Docker builder stage during `docker build`. No local tools needed.

`services/webapp/Dockerfile`'s `builder`/`webapp`/`worker` stages all `FROM` a shared `php-base` stage (`debian:bookworm-slim` + the Sury PHP repo + the PHP extensions all three need) instead of three different official images (`node:*`, `nginx:*`, `debian:*`) as before — that let BuildKit actually share cache across them, and dropped `nginx:*-bookworm`'s unused dynamic modules (image-filter's codec libs, geoip, njs, xslt — confirmed unused via grep on `nginx.conf`). `webapp` installs plain `nginx` from nginx.org's own apt repo (pinned via `Pin-Priority`, not Debian's — Debian bookworm's `nginx`/`nginx-light` packages are pinned to an old 1.22.1 and conflict once nginx.org's repo is also configured), and `builder` installs Node.js via NodeSource instead of the official node image. Stage-specific extensions (`php8.4-cli`, `-fpm`, `-opcache`, `-memcached`) stay per-stage.

Build steps in `services/webapp/build.sh`:
- `config.env.php` — HOST, CSS/JS/TWIG hashes
- PHP/Twig/SQL/JSON copy and processing
- Parcel — CSS, JS, Images (with custom namer: no content hashes, preserves subdirs)
- Sitemap from Twig `SITEMAP-PRIORITY` annotations
- Twig lint + PHP syntax check (`php -l`) + phpmd 3.x
- fail2ban page generation
- PHPUnit tests (with memcached running, secrets from `.env.dev`)
- `composer install --no-dev` to strip dev deps before final image

### PHP Backend

Entry point: `src/api/rest/index.php` (REST API) and `src/index.php` (web routes), both bootstrapped through `src/inc/include.php`.

Request flow: **Slim routes → Middleware stack → Handlers → Store/Integrations**

- `src/inc/handlers/` — one handler per feature area
- `src/inc/middleware/` — AuthMiddleware (Firebase JWT), SessionMiddleware, content-type middlewares
- `src/inc/store/` — data persistence layer (SQLite via JsonStore and direct DB queries)
- `src/inc/dataclasses/` — typed data models (Application, User, Category, SM, etc.)
- `src/inc/integrations/` — external services: Mail (Mailgun), Geolocation (Google Maps/Nominatim), ALPR plate recognition (OpenALPR, PlateRecognizer), OpenAI

### Configuration

Secrets and environment-specific config come from env files (all gitignored):

| File | Used by | Contents |
|------|---------|----------|
| `services/.env.dev` | dev Docker containers, builder tests | All app secrets (SMTP, S3, Crypto, APIs) |
| `services/.env.staging` | staging server Docker | Staging-specific values |
| `services/.env.prod` | prod server Docker | Prod-specific values |

PHP reads all config via `getenv()` — no `config.php` file required. Key constants:
- `APP_ENV` — environment detection (`prod`/`staging`/`dev`)
- `APP_HOST` — hostname for URLs and CDN paths
- `APP_ROOT` — server root (`/var/www/uprzejmiedonosze.net/`)
- `CRYPTO_KEY/IV/TAG` — encryption
- `OAUTH_PRIVATE_KEY` — RSA private key (PEM) for the OAuth provider; `OAUTH_ENCRYPTION_KEY` — base64 32-byte key
- `B2_KEY/SECRET/BUCKET/ENDPOINT/REGION` — Backblaze B2 object storage
- `MEMCACHED_HOST` — memcached hostname

### Frontend

Module-based vanilla JS — each page has its own entry file in `src/js/`. Shared utilities in `src/js/lib/`.

Firebase Authentication handles login (Google + email/password). Dev profile uses the Firebase Emulator (auto-started in Docker).

### Configuration Files

Key JSON configs in `src/api/config/`:
- `categories.json` — report categories
- `sm.json` — city guard stations (processed by `tools/sm-parser.js`)
- `stop-agresji.json` — police stations (processed by `tools/sm-parser.js`)
- `police-stations.csv` → `police-stations.pjson` via `tools/police-stations.php`
- `badges.json` — validated by `tools/badges-validator.js`

### Database

SQLite at `services/devroot/db/store.sqlite` (dev, mounted as a volume) or `/var/www/[host]/db/store.sqlite` (staging/prod, mounted from server filesystem).

Schema: `src/sql/base_schema.sql`; migrations: `src/sql/migration_*.sql`.

PHPUnit tests use `services/devroot/db/store.sqlite` as a fixture (via `TEST_DB` env var in bootstrap).

### CDN / Image Storage

- **Dev**: images saved to container's internal `/var/www/uprzejmiedonosze.net/cdn2/` (lost on restart — acceptable)
- **Staging**: `cdn2stg/` directory on server, synced to Backblaze B2
- **Prod**: `cdn2/` directory on server, synced to Backblaze B2

CDN prefix logic: `isStaging() ? 'cdn2stg' : 'cdn2'` — controlled by `APP_ENV`.

### Logging

App code logs via three functions in `src/inc/Logger.php`: `log_debug($msg)` (non-prod only), `log_info($msg, $force = false)` (dev/staging always, prod only with `$force`), `log_error($msg, ?\Throwable $e = null)` (always, with a stack trace).

**dev/test**: no journald socket is mounted, so every call falls back to `error_log(..., 'php://stderr')` — readable via `docker logs builder`/`docker logs webapp`.

**staging/prod**: `webapp-srv`, `face-detect-consumer`, `worker-cron` bind-mount the host's `/run/systemd/journal` (`services/compose.yml`). `Logger.php` sends each log line there as its own syslog datagram over a Unix datagram socket (`stream_socket_client('udg://...')`), with a real PRI (`facility<<3 | severity`) — journald/rsyslog forward that to Papertrail with the correct severity already attached, tagged with `LOG_IDENT` (e.g. `staging-webapp-srv`). nginx's `error_log` does the same (`syslog:server=unix:/run/systemd/journal/dev-log,...`, generated into `/etc/nginx/error_log.conf` by `init.sh` depending on whether that socket exists); `access_log` stays on stdout.

This exists because of a dead end that's worth knowing about if you're touching this again: writing app logs to `php://stdout` vs `php://stderr` and relying on Docker's `logging: driver: syslog` to turn that into severity **does not work** here. php-fpm's `catch_workers_output = yes` (`services/webapp/www.conf`) merges every worker's stdout *and* stderr into FPM's own single `error_log` destination (`/dev/stderr`, set in the Dockerfile) before Docker ever sees two separate streams — so every app log line, regardless of what PHP stream it targeted, ends up on the container's stderr and gets tagged `ERROR` in Papertrail. Confirmed empirically on staging 2026-09-28. `catch_workers_output` can't just be turned off either — without it, stray `stdout`/`stderr` writes from app code go to `/dev/null` per the FastCGI spec. Sending real syslog datagrams straight to journald sidesteps FPM's stream handling entirely.

php-fpm's own operational log (`error_log` in `php-fpm.conf`, master start/stop, worker respawns — not app-level logging) stays on `/dev/stderr` as-is; those are genuinely error-level FPM internals.

### Environments

| | dev | staging | prod |
|---|---|---|---|
| Host | `localhost` | `staging.uprzejmiedonosze.net` | `uprzejmiedonosze.net` |
| APP_ENV | `dev` | `staging` | `prod` |
| webapp port | 80 | 8081 (localhost) | 8080 (localhost) |
| Firebase | Emulator | Real project | Real project |
| Sentry | off | off | on |
| S3 | off | on | on |
| CDN prefix | `cdn2` | `cdn2stg` | `cdn2` |

**shadow** (Docker era, added 2026-10-06): a third server deploy target at `/opt/uprzejmiedonosze-shadow` on the host — a full prod mirror (`APP_ENV=prod` inside the containers, so PHP behaves like prod including real email sending), with its own baked `APP_HOST=shadow.uprzejmiedonosze.net`, port 18082, data root `/var/www/shadow.uprzejmiedonosze.net/` (legacy db/cdn2 reused). Bare app like staging (webapp-srv + memcached only — its worker would run s3-sync/db-backup against the prod B2 buckets otherwise). Traefik router is namespaced `ud-shadow` via `TRAEFIK_ENV` in `services/.env.shadow` (nested `${VAR:-${VAR2}}` interpolation); logs use `LOG_IDENT=shadow-webapp-srv`. Deploy with `make deploy-shadow` (tag = git SHA). Replaces the legacy rsync `make shadow` target (dead since the edge cutover).

## Agent Workflows & Tips

- **NEVER use `sudo`** (or any require-password root escalation). If something genuinely needs root, stop and ask the user to run it — never attempt `sudo` yourself. This applies equally to **remote hosts over SSH** — including `sudo -n`; it counts as sudo. If a file/command needs root there, report what you need and let the user run it.
- **Fetching App IDs:** You can find the internal Application ID for a ticket number (e.g., `UD/X/Y`) by querying the SQLite database on the production server via SSH:
  `ssh uprzejmiedonosze.net "sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite \"select key from applications where json_extract(value, '$.number') = upper('UD/X/Y') limit 1\""`
- **Checking Geo Units:** You can check the administrative unit (powiat, gmina) and assigned law enforcement unit by coordinates via the staging API:
  `curl -s -H "Cookie: UDSESSIONID=<VALUE>" "https://staging.uprzejmiedonosze.net/api/geo/LAT,LON/n"` (The cookie value can be found in `cypress/support/commands.js`).
- **City Guards vs Police Priority:** When configuring units in `sm.json`, note that City Guards (Straż Miejska) have priority over Police. A single key should represent one specific formation. If a municipality needs to be redirected to Police because there is no City Guard for that specific area, create a separate key for the Police station (e.g., "Komisariat Policji w ...") and map the municipality (`parent`) to it, while preserving any existing City Guard entries for the main city.
- **Flagging a Dissolved (zlikwidowana) SM Unit:** Reports never store more than the lowercased `sm.json`/`police.json` key (`Application::smCity`), resolved live on every render via `SM::resolve()`. Because of that, **never rename or reuse the key of a dissolved unit** — old reports would silently start rendering under whatever new unit takes over that key. Instead, set `"active": false` on the existing entry (keep its key/address/email untouched, as historical fact) and optionally add a `hint` explaining the situation. `SM::guess()`/`Police::guess()` skip `active === false` entries so new reports fall through to the next matching level (city → gmina → powiat), while `SM::resolve()` still returns them unchanged so historical reports keep displaying correctly. This supersedes the older ad hoc patterns used for Zielonka (2021, overwrote the entry in place, losing historical address data) and Murowana Goślina (2025, renamed the key — which broke resolution for reports sent before the rename).
- **Investigating Logs with Papertrail:** Use the `papertrail` skill (curl against the SolarWinds Observability API), not the `papertrail` CLI gem — the CLI only returns data from roughly the last hour regardless of `--min-time`, which silently misses older matches. For example, once you fetch the Application ID (e.g., `DGwu66uMCYBb`), query its logs with `filter=DGwu66uMCYBb`. To trace a user's full session and actions (like checking which geo coordinates they queried, e.g., `/api/geo/...`), filter by their IP address found in the initial logs: `filter=37.30.44.131 geo`.
