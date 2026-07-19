# Uprzejmie Donoszę

**Uprzejmie Donoszę** ("Politely Report") — a Polish civic reporting platform for citizens to file parking violations and traffic safety complaints to appropriate authorities (city guards, police departments).

**Uprzejmie Donoszę** — polski serwis obywatelski umożliwiający zgłaszanie naruszeń przepisów (głównie nielegalnego parkowania) do straży miejskiej lub policji. Upraszcza proces tworzenia poprawnego zgłoszenia wraz ze zdjęciami i lokalizacją oraz ułatwia przesłanie go do odpowiednich służb.

Stack: PHP 8.4 (Slim 4), Twig templates, Vanilla JS (ES modules), SCSS, SQLite, Firebase Auth, Parcel 2 bundler, Docker/nginx.

More information: [uprzejmiedonosze.net](https://uprzejmiedonosze.net/)

---

# How to start

## Prerequisites

- **Docker** (CE or Desktop) — the only required local tool; all build steps run inside containers
- **Git**

## Cloning

```bash
git clone git@github.com:uprzejmiedonosze/uprzejmiedonosze.git
cd uprzejmiedonosze
```

## Local config

**`config.dev.php`** (repo root, committed) is the dev bootstrap: fixture crypto keys
(matching `services/devroot/db/store.sqlite`), Mailpit/mail defaults, and API placeholders.
After clone, `make dev` is enough — no extra setup required.

Docker mounts this file into the webapp. Constants from the file take precedence; Docker env
vars (from optional `services/.env.dev`) only apply to settings **not** already defined there.

Optional **`services/.env.dev`** (gitignored) — only if you need real third-party tokens, e.g.:

```bash
MAPBOX_API_TOKEN=...
GOOGLE_MAPS_API_TOKEN=...
# ... see AGENTS.md for the full list
```

Ask the maintainer for a pre-filled `services/.env.dev` if you're joining the project.

## Firebase setup

The dev profile starts a **Firebase Auth Emulator** automatically — no real Firebase project needed for local development. The emulator UI is available at `http://localhost:4000`.

If you need a real Firebase project (for staging/prod), see the maintainer.

## Email preview (Mailpit)

The dev profile also starts **Mailpit** — outgoing mail is captured locally instead of hitting Mailgun. After sending a report, open the inbox at `http://localhost:8025` to preview the message body and PDF/ZIP attachments.

Configured via `MAILER_DSN` in `config.dev.php` (`smtp://mailpit:1025`). If the DSN is missing, dev falls back to dry-run (status updates, no message captured).

## Running

```bash
make dev
```

This is equivalent to:

```bash
docker compose -f services/compose.yml -p dev --profile dev up --build
```

(`services/.env.dev` is optional — see [Local config](#local-config).)

The first run takes a few minutes (builds Docker images, compiles CSS/JS). Subsequent runs are fast thanks to Docker layer cache.

Once running, open:
```
http://localhost
```

The Firebase emulator maps all logins to the pre-filled test account `e@nieradka.net`.

## Day-to-day development

**Edit PHP/Twig/SQL/JSON files** — the `builder` container watches `src/` with `inotifywait` and copies changes automatically. Reload the browser.

**Edit SCSS/JS** — Parcel watch rebuilds CSS/JS automatically (typically <2s with cache). Reload the browser.

**Open Firebase emulator UI:**
```bash
make emulator-ui
```

**Open Mailpit (local email inbox):**
```bash
make mailpit-ui
```

**View logs:**
```bash
docker logs webapp          # nginx + PHP-FPM (stderr)
docker logs builder         # build output, watch events
```

**Open a shell in the web container:**
```bash
docker exec -it webapp bash
```

**Run PHPUnit tests:**
```bash
make test
```

## MCP server

The app exposes a [Model Context Protocol](https://modelcontextprotocol.io) server at
**`POST /mcp`** (Streamable HTTP), so AI assistants (ChatGPT, Claude, MCP Inspector) can
read and update the signed-in user's reports. **`GET /mcp`** is a different, human-facing
page (main app, session + Twig): connect instructions for anyone, plus the signed-in user's
connected apps (with a revoke action) if they have any.

**Connecting** — a client only needs the URL `https://<host>/mcp`:

1. It discovers the OAuth endpoints via `/.well-known/oauth-protected-resource` and
   `/.well-known/oauth-authorization-server`.
2. It registers itself via Dynamic Client Registration (`POST /oauth/register`).
3. The user is sent to `/oauth/authorize`, signs in with the **existing Firebase login**,
   and approves a consent screen.
4. The client gets an opaque access token (+ refresh token) and calls `/mcp` with it.

Users review and revoke connected clients at **`/mcp`** (`/polaczone-aplikacje.html` redirects there).

**Tools & scopes:**

| Tool | Scope | Purpose |
|------|-------|---------|
| `list_reports` | `reports:read` | list the user's reports |
| `get_report` | `reports:read` | fetch one report by id |
| `update_report_status` | `reports:status:write` | record the authority's response |

Tokens are **opaque** (stored hashed, instantly revocable, audience-bound to `/mcp`);
access tokens live 1h, refresh tokens 30d (sliding). Code: `src/api/mcp/` (server),
`src/api/oauth/` + `src/inc/oauth/` (OAuth provider), `src/inc/mcp/` (tools). See
[API.md](API.md) for the endpoints.

**Testing locally** — with `make dev` running, point
[MCP Inspector](https://github.com/modelcontextprotocol/inspector) at `http://localhost/mcp`
and sign in through the Firebase emulator. Use **Google** sign-in: the email-link option
opens a new browser tab, which breaks Inspector's per-tab OAuth state. In dev the login is
pinned to one fixture identity (`e@nieradka.net`), regardless of which emulator account you use.

## Project structure

```
services/
├── compose.yml              # All Docker services (profiles: dev / staging / prod)
├── .env.dev                 # Optional extra secrets (gitignored)
├── face-detector/           # Python face detection service
├── webapp/
│   ├── Dockerfile           # builder + webapp + worker stages
│   ├── build.sh             # Full build script (runs inside Docker)
│   ├── watch.sh             # Dev watch loop (inotifywait + parcel watch)
│   ├── nginx.conf           # nginx configuration
│   └── crontab              # supercronic schedule for worker-cron
└── devroot/
    └── db/                  # Dev SQLite databases (mounted into container)

src/                         # All source files (never edit export/ directly)
export/                      # Built artifacts (gitignored, populated by builder)
config.dev.php               # Committed dev bootstrap (crypto fixture, Mailpit, etc.)
```

## Troubleshooting

**Build logs (verbose):**
```bash
docker logs builder --follow
```

**PHP errors:**
```bash
docker logs webapp 2>&1 | grep -i error
```

**Reset dev data:**
```bash
# Restart the webapp container (data in devroot/db/ persists between restarts)
docker compose -f services/compose.yml --profile dev restart webapp

# Wipe devroot DB (restores to committed fixture state)
git restore services/devroot/db/
```

**Init dev database from scratch:**
```bash
make init-db-dev
```
