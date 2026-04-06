# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Uprzejmie Donosze** ("Politely Report") — a Polish civic reporting platform for citizens to file parking violations and traffic safety complaints to appropriate authorities (city guards, police departments).

Stack: PHP 8.2+ (Slim 4), Twig templates, Vanilla JS (ES modules), SCSS, SQLite, Firebase Auth, Parcel 2 bundler, Docker/nginx.

## Commands

```bash
# Install dependencies
make install            # npm install + mise install + composer install

# Local development
make dev-run            # Build Docker image and start with Firebase emulator (first time)
make dev                # Refresh sources in running Docker (day-to-day)
make api                # Run PHP API server locally on :8080 (without Docker)

# Build
make css                # Compile SCSS → export/public/css/
make js                 # Bundle JS → export/public/js/
make minify             # Full build: js, css, php, twig, config

# Tests
make test-phpunit       # Run PHPUnit tests
make cypress-local      # Run Cypress E2E tests against local Docker
make lint-twig          # Lint Twig templates
make lint-php           # phpmd static analysis on PHP

# Deployment
make staging            # Deploy to staging.uprzejmiedonosze.net
make prod               # Deploy to production (requires main branch + clean git + cypress passing)
make quickfix           # Quick hotfix to production

# Utilities
make clean              # Remove export/, .parcel-cache/, generated env files
make log-from-last-prod # Commits since last production release
make diff-from-last-prod # Diff since last production release
```

**Running a single PHPUnit test:**
```bash
./vendor/bin/phpunit --filter TestClassName tests/
```

**Running a single Cypress test:**
```bash
CYPRESS_BASE_URL=http://127.0.0.1 ./node_modules/.bin/cypress run --spec "cypress/e2e/your-test.cy.js" --e2e --env DOCKER=1
```

## Architecture

### Build Pipeline

All source lives in `src/`, built artifacts go to `export/` (never edit export directly):

- `src/scss/index.scss` → Parcel → `export/public/css/index.css`
- `src/js/*.js` → Parcel → `export/public/js/*.js`
- `src/inc/` → copied + linted → `export/inc/`
- `src/templates/` → copied → `export/templates/`
- `src/api/config/*.json` → jq minified → `export/public/api/config/`
- Cache-busting hashes (`CSS_HASH`, `JS_HASH`, `TWIG_HASH`) are computed from file contents and injected into `src/config.env.php` at build time.

### PHP Backend

Entry point: `src/api/rest/index.php` (REST API) and `src/index.php` (web routes), both bootstrapped through `src/inc/include.php`.

Request flow: **Slim routes → Middleware stack → Handlers → Store/Integrations**

- `src/inc/handlers/` — one handler per feature area (ApplicationHandler, SessionApiHandler, ApiAiHandler, StaticPagesHandler, etc.)
- `src/inc/middleware/` — AuthMiddleware (Firebase JWT), SessionMiddleware, content-type middlewares (PdfMiddleware, CsvMiddleware, XlsMiddleware, JsonBodyParser)
- `src/inc/store/` — data persistence layer (SQLite via JsonStore and direct DB queries)
- `src/inc/dataclasses/` — typed data models (Application, User, Category, SM, Petition, etc.)
- `src/inc/integrations/` — external services: Mail (Mailgun/Google), Geolocation (Google Maps/Nominatim), ALPR plate recognition (OpenALPR, PlateRecognizer), OpenAI
- `config.php` — local secrets/config (not committed); `config.dev.php` / `config.prod.php` for env overrides

### Frontend

Module-based vanilla JS — each page has its own entry file in `src/js/sites/`. Shared utilities in `src/js/lib/` (including `Api.js`, the central HTTP client).

Firebase Authentication handles login (Google + email). The Firebase project switches between emulator (dev) and real project (staging/prod) via build-time config.

### Configuration Files

Key JSON configs in `src/api/config/`:
- `categories.json` — report categories/types
- `sm.json` — city guard stations (processed by `tools/sm-parser.js`)
- `stop-agresji.json` — police stations (processed by `tools/sm-parser.js`)
- `police-stations.csv` → converted to `police-stations.pjson` via `tools/police-stations.php`
- `badges.json` — validated by `tools/badges-validator.js`

### Database

SQLite at `docker/db/store.sqlite` (dev) or server path (staging/prod). Schema in `src/sql/base_schema.sql`; migrations as `src/sql/migration_*.sql`.

### Environments

| Target | HOST | Notes |
|--------|------|-------|
| dev | localhost | Firebase emulator, Docker |
| staging | staging.uprzejmiedonosze.net | Real Firebase |
| prod | uprzejmiedonosze.net | Requires `main` branch + clean git + Cypress |

Before `make dev`, ensure `config.php` exists (local secrets file; see `config.dev.php` as reference).

After switching branches or environments, run `make clean` first — the build system detects env/branch changes via `.branch-env` and will error if they mismatch.
