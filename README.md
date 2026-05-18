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

## Local secrets setup

Create `services/.env.dev` (gitignored) with your dev credentials. Use the structure below — all values are required for full functionality, but the app will start without optional ones:

```bash
# Mandatory for encryption / sessions
CRYPTO_KEY=...
CRYPTO_IV=...
CRYPTO_TAG=...

# Firebase, Maps, ALPR, Mail, etc. (optional — app runs without them)
MAPBOX_API_TOKEN=...
GOOGLE_MAPS_API_TOKEN=...
# ... see AGENTS.md for the full list
```

Ask the maintainer for a pre-filled `services/.env.dev` if you're joining the project.

## Firebase setup

The dev profile starts a **Firebase Auth Emulator** automatically — no real Firebase project needed for local development. The emulator UI is available at `http://localhost:4000`.

If you need a real Firebase project (for staging/prod), see the maintainer.

## Running

```bash
make dev
```

This is equivalent to:

```bash
docker compose -f services/compose.yml --env-file services/.env.dev -p dev --profile dev up --build
```

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

## Project structure

```
services/
├── compose.yml              # All Docker services (profiles: dev / staging / prod)
├── .env.dev                 # Local secrets (gitignored, create manually)
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
