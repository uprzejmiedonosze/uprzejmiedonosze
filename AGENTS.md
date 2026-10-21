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
make lint-css            # stylelint on src/scss (requires `npm install` in tools/)
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
- **Lint hook:** `.claude/hooks/lint-edited-file.sh` runs after every Write/Edit (stylelint for `src/scss`, `php -l`,
  twig-linter, JSON validity for `src/api/config`). A blocking message from it means: fix the reported problem now.
- **Skills** (`.claude/skills/`): `sm-unit` (SM/Police units), `new-migration` (SQLite schema changes), `cypress`.
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
  `curl -s -H "Cookie: UDSESSIONID=<VALUE>" "https://staging.uprzejmiedonosze.net/api/geo/LAT,LON/n"` (`SESSION_ID` in `cypress/support/commands.js`; HTTP 403 = the staging test session expired — see the `sm-unit` skill for a Nominatim fallback).
- **Logs:** use the `papertrail` skill (SolarWinds API), not the `papertrail` CLI gem (it silently returns only ~the last
  hour). Filter by app ID (e.g. `filter=DGwu66uMCYBb`), then by the user's IP to trace their session (`filter=37.30.44.131 geo`).
- **SM/Police units:** City Guards have priority over Police; never rename or reuse a unit key — see `src/api/AGENTS.md`
  and ADR 0001.

<!-- gitnexus:start -->
# GitNexus — Code Intelligence

This project is indexed by GitNexus as **uprzejmiedonosze** (3229 symbols, 8422 relationships, 251 execution flows).

> Index stale? Run `node .gitnexus/run.cjs analyze --index-only` from the project root — it auto-selects an available runner. No `.gitnexus/run.cjs` yet? Bootstrap with `npx`, `bunx`, or `pnpm dlx` — e.g. `bunx gitnexus@latest analyze` (npm 11 npx crash; #1939).

## Always Do

- **MUST run impact before editing.** Use `impact({target: "symbolName", direction: "upstream"})` or `node .gitnexus/run.cjs impact "symbolName" --direction upstream --repo .`; report callers, processes, and risk. Never substitute grep for graph analysis.
- **MUST analyze graph changes before committing.** Use `detect_changes({scope: "all"})` (MCP) or `node .gitnexus/run.cjs detect-changes --scope all --repo .` (CLI fallback). `partial: true` or `truncated: true` is not a clean check — a zero means unseen, not unaffected; re-run it. For regression review: `detect_changes({scope: "compare", base_ref: "main"})` or `node .gitnexus/run.cjs detect-changes --scope compare --base-ref "main" --repo .`.
- MUST warn on HIGH/CRITICAL `risk` pre-edit; never use `riskSharedAxes` to waive a HIGH/CRITICAL `risk` warning. Compare File/symbol: MCP File omits axes; Graph-RAG expands File.
- **MUST treat `risk: UNKNOWN` as unresolved, not as low.** An empty caller set is not evidence the symbol is unused — it can also mean the callers are not resolvable by the index (plain-object property access, dynamic dispatch, cross-language calls). `impact` pairs `UNKNOWN` with a `riskNote` saying so. Confirm with a text search before treating the symbol as safe to change or delete; do not proceed on the strength of a zero.
- **MUST use `query({search_query: "concept"})` for concepts/flows, `context({name: "symbolName"})` for a named symbol, or `impact` for blast radius, on read-only callers, dependencies, imports, or execution flow.** Graph first; text search only for empty/`UNKNOWN`/literals.
- For security review, `explain({target: "fileOrSymbol"})` lists taint findings (source→sink flows; needs `analyze --pdg`).

## Never Do

- NEVER edit a function, class, or method before MCP/CLI impact analysis.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis, and never read `UNKNOWN` as an all-clear — it means the walk could not answer, which is the one verdict that requires confirming by other means.
- NEVER rename symbols with find-and-replace — use `rename` which understands the call graph.
- NEVER commit before MCP/CLI graph change analysis.

## Resources

| Resource | Use for |
| --- | --- |
| `gitnexus://repo/uprzejmiedonosze/context` | Codebase overview, check index freshness |
| `gitnexus://repo/uprzejmiedonosze/clusters` | All functional areas |
| `gitnexus://repo/uprzejmiedonosze/processes` | All execution flows |
| `gitnexus://repo/uprzejmiedonosze/process/{name}` | Step-by-step execution trace |

## CLI

| Task | Read this skill file |
| --- | --- |
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus-cli/SKILL.md` |

<!-- gitnexus:end -->
