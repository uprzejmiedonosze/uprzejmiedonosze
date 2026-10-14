# AGENTS.md

Guidance for coding agents (Claude Code, opencode, …) working in this repository.
This file is the **index**: always-relevant facts only. Area-specific rules live in nested `AGENTS.md` files
(loaded when you work in that directory) and in `docs/`.

## Project Overview

**Uprzejmie Donosze** ("Politely Report") — a Polish civic reporting platform for citizens to file parking violations
and traffic safety complaints to appropriate authorities (city guards, police departments).

Stack: PHP 8.4 (Slim 4), Twig templates, Vanilla JS (ES modules), SCSS, SQLite, Firebase Auth, Parcel 2 bundler, Docker/nginx.

## Commands

```bash
make dev                 # full dev stack (docker compose --profile dev)
make init-db-dev         # initialize dev SQLite database (run once)
make emulator-ui         # Firebase emulator UI at http://localhost:4000
make test                # PHPUnit in a throwaway container — ALWAYS use this, never call phpunit directly
make cypress-local       # Cypress E2E against local dev (or the `cypress` skill)
make deploy-staging | deploy-shadow | deploy-prod
make log-from-last-prod  # commits since last production release
make diff-from-last-prod
make clean               # remove export/, .parcel-cache/
```

Single test: `docker exec webapp ./vendor/phpunit/phpunit/phpunit --filter TestClassName tests/`

## Map

| Path | What | Read first |
|---|---|---|
| `src/inc/` | PHP backend: handlers, `API.php` (shared domain logic), store, dataclasses, middleware, integrations | [`src/inc/AGENTS.md`](src/inc/AGENTS.md) |
| `src/index.php` | Web routes (Slim) | ↑ |
| `src/api/rest/`, `API.md` | REST API for mobile/MCP + its contract | [`src/api/AGENTS.md`](src/api/AGENTS.md) |
| `src/api/config/` | Domain JSON config (`sm.json`, `police.json`, categories, statuses…) | ↑ |
| `src/templates/` | Twig pages and partials, layout flags | [`src/templates/AGENTS.md`](src/templates/AGENTS.md) |
| `src/js/` | Frontend entries, per-page modules, shared `lib/` | [`src/js/AGENTS.md`](src/js/AGENTS.md) |
| `src/scss/` | Styles: rd25 tokens/foundation in `lib/` | [`src/scss/AGENTS.md`](src/scss/AGENTS.md) |
| `src/sql/` | `base_schema.sql` + `migration_*.sql` (applied manually) | `src/inc/AGENTS.md` → „Nowa tabela” |
| `services/` | Docker, build pipeline, environments, logging, dev-env gotchas | [`services/AGENTS.md`](services/AGENTS.md) |
| `tests/` | PHPUnit (`DatabaseTestCase` = transaction per test) | — |
| `cypress/` | E2E tests | `cypress` skill |
| `tools/` | Build-time processors (`sm-parser.js`, `badges-validator.js`, `police-stations.php`), eslint | — |
| `docs/adr/` | Architecture decisions — the *why* behind non-obvious code | [`docs/adr/README.md`](docs/adr/README.md) |
| `docs/glossary.md` | Polish domain terms ↔ code names | [`docs/glossary.md`](docs/glossary.md) |

## Ground rules

- **`src/` is the source, `export/` is build output — never edit `export/`.** PHP reads config JSON from `export/`
  (processed), not from `src/`.
- **Reuse before writing.** Check the helper catalogs in `src/inc/AGENTS.md` and `src/js/AGENTS.md`; domain logic for
  reports goes in `src/inc/API.php` so web, REST and MCP share one implementation.
- **Follow the nearest existing example** named in the area `AGENTS.md` (handler, store, page, migration) rather than
  inventing a new pattern. Match surrounding naming and comment density; new identifiers in English, Polish domain
  names kept as-is (see glossary).
- **Config & secrets** come from `services/.env.{dev,staging,prod,shadow}` (gitignored) via `getenv()` in
  `src/inc/config.php`. Key constants: `APP_ENV`, `APP_HOST`, `APP_ROOT`, `CRYPTO_*`, `OAUTH_*`, `B2_*`, `MEMCACHED_HOST`.
- **Logging:** only `log_debug` / `log_info` / `log_error` from `src/inc/Logger.php` (ADR 0002).
- **Verification:** `make test`; UI changes via `curl` against local docker — the user does visual review.
- **Commits:** use the `/commit` command (tests → changelog on `main` → commit → push on `main`).
- **Keep docs alive:** when you change a convention, a recipe's example file, or make a non-obvious decision, update the
  relevant `AGENTS.md` or add an ADR in the same commit.

## Safety

- **NEVER use `sudo`** — locally or on remote hosts over SSH (including `sudo -n`). If root is needed, stop and give the
  user the command.
- Commands that touch prod storage/infra (e.g. `rclone` on B2, migrations on prod DB): show the command, let the user run it.
- **shadow mutates prod data** (shared `store.sqlite`, real email sending) — see `services/AGENTS.md`.

## Operational recipes

- **App ID from ticket number** (`UD/X/Y`):
  `ssh uprzejmiedonosze.net "sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite \"select key from applications where json_extract(value, '$.number') = upper('UD/X/Y') limit 1\""`
- **Geo unit for coordinates** (powiat, gmina, assigned SM/Police):
  `curl -s -H "Cookie: UDSESSIONID=<VALUE>" "https://staging.uprzejmiedonosze.net/api/geo/LAT,LON/n"` (cookie in `cypress/support/commands.js`).
- **Logs:** use the `papertrail` skill (SolarWinds API), not the `papertrail` CLI gem (it silently returns only ~the last
  hour). Filter by app ID (e.g. `filter=DGwu66uMCYBb`), then by the user's IP to trace their session (`filter=37.30.44.131 geo`).
- **SM/Police units:** City Guards have priority over Police; never rename or reuse a unit key — see `src/api/AGENTS.md`
  and ADR 0001.
