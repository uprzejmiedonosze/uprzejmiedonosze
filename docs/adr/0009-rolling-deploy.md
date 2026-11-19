# 0009 — Wdrożenie bez przestoju: healthcheck + rolling update `webapp-srv`

**Status:** obowiązuje (wzorzec z `proyecto-art/proyectoart-site`, `deploy/deploy.sh`)

## Kontekst
`scripts/deploy.sh` robił `docker compose up -d`, czyli stop starego kontenera → start nowego. Do tego
`webapp-srv` nie miał HEALTHCHECK-a, więc `--wait` czekał tylko na stan „running”, a Traefik mógł kierować ruch
do kontenera, w którym php-fpm jeszcze nie wstał. Stały port hosta (`127.0.0.1:${APP_PORT}:80`) uniemożliwiał
uruchomienie dwóch kontenerów naraz.

## Decyzja
- `HEALTHCHECK` w etapie `webapp` (`Dockerfile`): `curl /healthz`, `--start-interval=1s` (nowy kontener jest
  routowalny ~1 s po starcie; bez tego pierwsza próba dopiero po 30 s). nginx odpowiada na `/healthz` przez
  `ping.path` php-fpm (`www.conf`), więc „healthy” = php-fpm żyje, nie tylko nginx.
- `deploy.sh`: nowy kontener startuje obok starego (`--scale webapp-srv=2 --no-recreate --wait`), po healthchecku
  i 5 s na Traefika stary jest zatrzymywany i usuwany. Gdy nowy nie przejdzie healthchecku, jest usuwany,
  a stary dalej obsługuje ruch (deploy kończy się błędem).
- `stop_signal: SIGQUIT` + `stop_grace_period: 30s`: nginx kończy trwające żądania (php-fpm odpowiada im do końca,
  bo żyje, dopóki nginx — PID 1 — nie wyjdzie).
- `webapp-srv` **nie publikuje portu hosta** (ruch idzie przez Traefik w sieci `edge`); `APP_PORT` w `.env.*` jest martwy.
- `/version.txt` (`BUILD_SHA` z `build-push.sh`) + label `org.opencontainers.image.revision`: `deploy.sh` sprawdza,
  że działający kontener niesie commit swojego obrazu, a `make deploy-*` — że `https://host/version.txt` == `HEAD`.
- `flock` na `.deploy.lock`: jedno wdrożenie naraz.

## Konsekwencje
- Dwa kontenery przez kilka sekund współdzielą `db/` (SQLite w WAL — bezpieczne) i sesje (memcached — nietknięty).
- Nie dodawaj z powrotem `ports:` do `webapp-srv` ani `container_name` — zepsuje `--scale`.
- Healthcheck sprawdza pulę php-fpm: przy zapchanej puli (`pm.max_children`) 3 nieudane próby po kolei oznaczą
  kontener `unhealthy`, a Traefik przestanie do niego routować. To świadomy kompromis; przy problemach
  z przeciążeniem sprawdź to najpierw.
- Workery (`worker-cron`, `face-detect-consumer`) są nadal zwykłym recreate.
