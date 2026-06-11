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
| `matomo` + `matomo-db` | | | ✓ | Analytics |

### Build Pipeline

All source lives in `src/`, built artifacts go to `export/` (never edit export directly).

**Dev**: The `builder` container mounts `src/` read-only and `export/` read-write. `watch.sh` runs an initial build then watches for file changes via `inotifywait` (PHP/Twig/JSON) and `parcel watch` (CSS/JS).

**Staging/prod**: `build.sh` runs inside the Docker builder stage during `docker build`. No local tools needed.

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
- `S3_KEY/SECRET/BUCKET/ENDPOINT/REGION` — Hetzner Object Storage
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
- **Staging**: `cdn2stg/` directory on server, synced to Hetzner S3 by `worker-cron`
- **Prod**: `cdn2/` directory on server, synced to S3

CDN prefix logic: `isStaging() ? 'cdn2stg' : 'cdn2'` — controlled by `APP_ENV`.

### Logging

PHP errors and `logger()` calls go to `php://stderr` → captured by `docker logs webapp`. No separate log files needed in development.

For production: nginx access/error logs are written to `/var/log/uprzejmiedonosze.net/` (volume-mounted from host).

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

## Agent Workflows & Tips

- **Fetching App IDs:** You can find the internal Application ID for a ticket number (e.g., `UD/X/Y`) by querying the SQLite database on the production server via SSH:
  `ssh nieradka.net "sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite \"select key from applications where json_extract(value, '$.number') = upper('UD/X/Y') limit 1\""`
- **Checking Geo Units:** You can check the administrative unit (powiat, gmina) and assigned law enforcement unit by coordinates via the staging API:
  `curl -s -H "Cookie: UDSESSIONID=<VALUE>" "https://staging.uprzejmiedonosze.net/api/geo/LAT,LON/n"` (The cookie value can be found in `cypress/support/commands.js`).
- **City Guards vs Police Priority:** When configuring units in `sm.json`, note that City Guards (Straż Miejska) have priority over Police. A single key should represent one specific formation. If a municipality needs to be redirected to Police because there is no City Guard for that specific area, create a separate key for the Police station (e.g., "Komisariat Policji w ...") and map the municipality (`parent`) to it, while preserving any existing City Guard entries for the main city.
- **Investigating Logs with Papertrail:** You can use the `papertrail` CLI tool to investigate application logs. For example, once you fetch the Application ID (e.g., `DGwu66uMCYBb`), you can query its logs: `papertrail DGwu66uMCYBb`. You can also extend the search window: `papertrail --min-time 'yesterday' DGwu66uMCYBb`. To trace a user's full session and actions (like checking which geo coordinates they queried, e.g., `/api/geo/...`), you can filter by their IP address found in the initial logs: `papertrail 37.30.44.131 geo`.
