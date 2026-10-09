# REST API Documentation

*Note: Due to the framework's strict routing (Slim 4), endpoints corresponding to the root of a group **must** include a trailing slash (e.g., `/api/rest/user/` instead of `/api/rest/user`). Missing trailing slashes will result in a 404 Not Found error.*

## Kontrakt API a aplikacja mobilna (wymuszenie aktualizacji)

Kontrakt jest wersjonowany numerem `VISION_SCHEMA` (`src/inc/integrations/VisionSchema.php`) = `SCHEMA` w appce (`src/lib/vision.ts`);
podbijamy go przy każdej zmianie kontraktu. Aplikacja wysyła w każdym żądaniu `X-UD-Schema: <SCHEMA>` (oraz informacyjnie
`X-UD-Client: pro/<wersja> (<build>; <ios|android>)`). **Kompatybilność jest tylko „w dół”:** klient ze schematem równym lub
wyższym niż backend działa (nowa appka ze starszym backendem – OK), a klient ze schematem **niższym** niż `VISION_SCHEMA` backendu
(np. appka 7, backend 8) dostaje na każdym `/api/rest/*` **426** z `{error, status, schema, serverSchema, storeUrl}` i pokazuje
blokujący ekran „Zaktualizuj”. Żądania bez nagłówka (web, MCP) nie są ograniczane.
Decyzję podejmuje wyłącznie backend – appka nie porównuje wersji u siebie, tylko reaguje na 426 (pierwsze żądanie po starcie to
`POST /api/verify-token`, więc blokada pojawia się od razu). `/api/config/app.json` (publiczny) służy już tylko do miękkiej zachęty:
`latestVersion` (wersja appki, per platforma) i `storeUrl`. **Kolejność wdrożenia zmiany kontraktu:** podbić `SCHEMA` w appce → nowy build
w sklepach → dopiero potem backend z podbitym `VISION_SCHEMA` (od tej chwili starsze appki są blokowane).

## User endpoints

Requires authorization.

### GET `/api/rest/user/`

Returns current user data, including `lastLocation` (`"lat,lng"` of the last report, otherwise the geocoded home address; absent when neither is known) (`?fresh=1` recomputes `stats` instead of using the 24 h cache), `stats` and `sexStrings` (the user's gendered phrases, e.g. `bylam`: "byłem"/"byłam"/"byłam/em" — the same lookup the web templates do with `config.sex`).

### PATCH `/api/rest/user/`

Creates a new user if it does not exist.

### PATCH `/api/rest/user/confirm-terms`

Marks terms of service as confirmed by the current user.

### POST `/api/rest/user/`

Updates current user data.

POST params (JSON body):

  * `name`
  * `address`
  * `msisdn` (optional)
  * `edelivery` (optional)
  * `stopAgresji` (optional, default 'SM', can be 'SA')
  * `shareRecydywa` (optional, default 'Y')

### DELETE `/api/rest/user/`

Self-service account deletion (same as the web "Skasuj konto"): removes reports, photos, passkeys and
OAuth connections, sends the farewell e-mail. Irreversible.

JSON body: `email` — the account's own e-mail, retyped as confirmation (mismatch → 422).

### GET `/api/rest/user/dashboard`

Everything the web `/app` dashboard shows, already localized for the user's sex (gendered level and
badge names, `introMsg`; the `{token}` placeholders in `levels.json` are resolved server-side):
`{name, stats, introMsg, levels[{id, desc, active}], rank, badges[{id, name, desc, img, earned, former}]}`; stats are always computed fresh (no cache).
`introMsg` and badge `desc` may contain HTML.

### GET `/api/rest/user/passkeys`

Returns `{passkeys: [{id, label, createdAt, lastUsedAt}]}`.

### DELETE `/api/rest/user/passkeys/{id}`

Removes one of the user's passkeys.

### POST `/api/rest/user/passkeys/register-options` / `register-verify`

Native app registration (WebAuthn). There is no cookie session, so the challenge travels as `state`:
`register-options` returns `{options, state}` (`options` = WebAuthn creation options, base64url);
`register-verify` takes `{state, id, clientDataJSON, attestationObject, transports}` (base64url) and returns
`{passkeys: [...]}`. `state` is single-use, TTL 120 s. The Android origin (`android:apk-key-hash:<hash>`) must be
whitelisted in `PASSKEY_ANDROID_KEY_HASHES` (comma-separated, per host); the host must equal `APP_HOST` (RP ID).

### POST `/api/rest/passkey/login-options` / `login-verify` (anonymous)

`login-options` returns `{options, state}` (discoverable credentials, empty `allowCredentials`);
`login-verify` takes `{state, id, clientDataJSON, authenticatorData, signature, userHandle}` and returns
`{customToken}` (Firebase custom token, 300 s) – the app exchanges it via `accounts:signInWithCustomToken`
and then calls `/api/verify-token`.

### GET `/api/rest/user/apps`

Returns user's applications (each with `recipient`).

GET params:

  * `status` (optional, default 'all' = everything except drafts; 'active' = like 'all' without `archived`, the web list default; or a single status)
  * `search` (optional, default '%')
  * `limit` (optional, default 0)
  * `offset` (optional, default 0)

## Application endpoints

Requires authorization.

All application endpoints return the application JSON plus a derived `recipient` object
(`{key, name, shortName, isPolice, automated, unknown, stopAgresjiForced}`) — who the report goes to.
`GET /api/rest/user/apps` returns `recipient` for every item too. Errors are `{error, status}`;
validation errors (HTTP 422) also carry `field` (`plateId`, `address`, `datetime`, `comment`,
`status`, `images`). These endpoints share their implementation (`src/inc/API.php`) with the
cookie-based web API (`/api/app/*`), so both behave the same.

### POST `/api/rest/app/new`

Creates a new, empty application linked to the currently authenticated user and returns the newly created application object.

No POST parameters are required.

### GET `/api/rest/app/{appId}`

Returns application data by id.

### POST `/api/rest/app/{appId}`

Saves the report form (web: "Dalej" → `/app/confirm`); status becomes `ready`. Requires both
photos to be uploaded already (otherwise 409).

POST params (JSON body):

  * `plateId` — min. 3 characters
  * `address` — the address shown to the user (web: `lokalizacja`), required
  * `addressGPS` (optional) — what the geocoder returned
  * `city`, `voivodeship`, `district`, `county`, `municipality`, `postcode` (optional)
  * `lat`, `lng` (optional)
  * `dtFromPicture` (1|0)
  * `datetime` — not in the future
  * `comment` (optional, default ''; required for category 0)
  * `category`
  * `witness` (optional bool, default false)
  * `extensions` (optional) — array `[6, 7]` or comma-separated string `"6,7"`
  * `stopAgresji` (optional) — `"SA"` (Policja) / `"SM"`, or bool; remembered as the account default

### PATCH `/api/rest/app/{appId}/status/{status}`

Changes application status.

### POST `/api/rest/app/{appId}/image`

Uploads one photo (≤ 3 MB, JPEG/PNG; stored ≤ 1600 px). `carImage` runs plate recognition (ALPR)
and fills `carInfo`. Only editable applications accept uploads.

Preferred contract — `multipart/form-data`:

  * `image` — the file
  * `pictureType` — `contextImage` | `carImage` | `thirdImage`
  * `dateTime` (optional, `carImage` only) — ISO, e.g. "2018-02-02T19:48:10"
  * `dtFromPicture` (optional) — `true` when `dateTime` comes from the photo
  * `latLng` ("53.4,14.5") or `lat` + `lng` (optional, `carImage` only)

Staged contract (UD Pro mobile) — instead of `image` send `photoId` (from `POST /api/rest/photos`) plus
`pictureType`. The server takes the bytes from its `wip` storage (no second upload), resizes/crops them and
reuses the plate recognition result computed once for that photo, so re-assigning the same `photoId` to another
slot (swapping roles) costs no extra ALPR call.
Optional `crop: "vehicle"` (with `pictureType: carImage`): the car photo becomes the winning vehicle cut out of the staged
photo with a 20 % margin on each side (clamped to the frame) — for one photo serving as both context and car. The stored ALPR
result is shifted to the crop, so ALPR is still called once. 422 (`images`) when no vehicle with a readable plate was found.
Optional `plate` (`carImage` only): plate text of the vehicle the user picked when several cars were detected; `carInfo`, the
plate crop and the vehicle crop then follow that reading instead of the photo's winner (no such reading → the winner). 404 when the `photoId` is unknown, expired or not yours. The staged
file is kept until the report is confirmed (`/finish`) or `WIP_TTL_HOURS` (24 h) pass.

### POST `/api/rest/photos/`

Stages one photo *before* it belongs to any report (`cdn2/{user}/wip/{photoId}.jpg`, not synced to S3, not publicly
served). `multipart/form-data`: `image` (JPEG/PNG, ≤ 3 MB), optional `dateTime`, `lat`, `lng` (EXIF read by the client).
Response `201`: `{ "photoId": "<32 hex>", "width": 1600, "height": 1200 }`. Limited to `WIP_RATE_MAX` per `WIP_RATE_WINDOW`.

Optional `derivedFrom` (a `photoId`), `region` (JSON `[x1,y1,x2,y2]`, fractions of the source frame) and `plate`: the uploaded file is a
full-resolution cut-out of an already analysed photo (the app crops the original from the gallery). No new ALPR call: the source
photo's reading of the chosen vehicle (`plate`, default the winner) is mapped onto the cut-out and date/GPS are inherited, so
the result can be assigned like any staged photo. 400 for an invalid region, 404 for an unknown source.

### GET|POST `/api/rest/photos/{photoId}/alpr`

Plate-recognition readings of a staged photo (PlateRecognizer only — no LLM; computed once per photo and stored with it).
Response: `{ "photoId", "width", "height", "detections": [{ "text": "ZS12345", "score": 0.97, "plate_bbox": [x1,y1,x2,y2],
"vehicle_bbox": [...], "vehicle_area": 0.31, "winner": true }] }` — boxes in 0..1000 space, `vehicle_area` = share of the frame,
`winner` = the reading picked by the server's candidate algorithm (score within a 0.1 tie band, then the larger vehicle).
The UD Pro app uses all detections to choose which photo is the car and which is context (`src/lib/roles.ts`). 404 when
the photo does not exist, 502 when ALPR is unavailable.

### Limit przetworzonych zdjęć (HTTP 402)

Każdy użytkownik ma limit **unikalnych** zdjęć przetworzonych przez płatnych dostawców (ALPR/LLM) w kroczącym oknie
`PHOTO_QUOTA_DAYS` (30 dni). Liczy się zdjęcie (sha1 bajtów), nie zgłoszenie: ponowne wysłanie tego samego pliku oraz
zdjęcie, którego wynik leży w cache ALPR, nie zużywają limitu. Wspólna pula dla appki Pro (REST) i MCP (`create_report_draft`,
tylko `carImage`); web bez zmian. Progi (aktywny patron Patronite wg kwoty): brak patronatu 50, ≥ 10 zł 100, ≥ 25 zł 300,
≥ 50 zł bez limitu (`config.php`: `PHOTO_QUOTA_FREE`, `PHOTO_QUOTA_TIERS`).

Przekroczenie → **402** `{error, status, quota}` z `POST /photos/{id}/alpr`, `POST /app/{id}/image` (gałąź `photoId`)
i `POST /vision/candidate`; w MCP – błąd narzędzia z tym samym komunikatem, bez tworzenia szkicu.
`quota` = `{used, limit|null, remaining|null, windowDays, resetsAt|null (unix), tier}`; ten sam obiekt jako `photoQuota`
w `GET /user/dashboard`, w odpowiedzi `/photos/{id}/alpr` i w wyniku MCP `create_report_draft`. Błąd dostawcy zwraca
zarezerwowane miejsce. Migracja: `src/sql/migration_20261006_photo_quota.sql`.

### GET `/api/rest/photos/quota`

Zużycie limitu przetworzonych zdjęć użytkownika (obiekt `quota`, patrz wyżej: `{used, limit|null, remaining|null, windowDays, resetsAt|null, tier}`).

### DELETE `/api/rest/photos/{photoId}`

Removes a staged photo (204, or 404 when it does not exist).

### PATCH `/api/rest/app/{appId}/fields`

Edits `externalId` (the SM/Police case number) and/or `privateComment` (private notes, visible only to the owner) — allowed
at any status, also after sending. JSON body with one or both string fields; unknown fields → 400. Response
`{ "app": {...}, "suggestStatusChange": true|false }` — `true` when the report is sent and has a case number (the web then
asks to switch the status to `confirmed-sm`, see `PATCH .../status/{status}`).

### POST `/api/rest/app/{appId}/plate-image`

Replaces the licence-plate crop (`carInfo.plateImage`, shown in the form, on the report page and in the PDF) with a client-made
crop. The UD Pro app cuts it from the full-resolution original (the server only has a ≤ 1600 px copy, i.e. ~80 px of plate).
`multipart/form-data`: `image` (JPEG/PNG, scaled down to 800 px wide). Needs a car photo (409 otherwise); owner only (403).

### DELETE `/api/rest/app/{appId}/image/{image}`

Removes `contextImage` | `carImage` | `thirdImage` (e.g. to replace a photo or drop the optional third one).

### GET `/api/rest/app/{appId}/confirmation`

Everything the confirmation step shows before sending/saving (web "Sprawdź przed wysłaniem"), built with the
same methods as the web template so gendered phrases match: `body` (formal text + extensions + comment),
`witness` ("Nie byłeś/byłaś świadkiem parkowania."), `shortAddress`, `plateId`, `vehicleBox` (pixels, or null when
the confirmed plate differs from the one read from the photo), `recipient {name, shortName, automated, unknown}`
and `sender {name, email, address, msisdn, edelivery}`. Owner only.

### POST `/api/rest/app/{appId}/finish`

"Potwierdź" — the report must have been saved with `POST /api/rest/app/{appId}` (status `ready`).
Sets status `confirmed` (assigns the report number `UD/x/y`) and performs the same side effects as the
web `/app/done`: last location, apps counter, recidivism, stats cache. Safe to repeat.

POST params (JSON body):

  * `send` (optional bool, default false) — also send it right away (only when the recipient has an automated channel)

Response: `{ app, edited, appsCount, isPatron, sendMode, sendError }` where `sendMode` is
`sent` | `manual` (recipient has no automated channel — finish on the web, `/app/send`) |
`failed` (automated channel exists but sending failed; report stays `confirmed`, see `sendError`) |
`not_requested`.

### PATCH `/api/rest/app/{appId}/send`

Sends an email with the application to police/city-guards station.

## Geolocation endpoints

Requires authorization.

### GET `/api/rest/geo/map?lat=&lng=&w=&h=`

Static map preview (PNG, Mapbox outdoors style, via the backend) for the report form. With `lat`/`lng` a pin
is drawn there; without them the map shows all of Poland. `w`/`h` default to 600×300 (max 640). Cached for a
day (`Cache-Control: private`); 404 when Mapbox is unavailable.

### GET `/api/rest/geo/search?q=`

Forward geocoding of a typed address (`q` = "Ulica 10, Miasto" — the comma/locality is required).
Returns `{lat, lng, address, sm, sa}` (same shape as the reverse-geocoding endpoints plus the
coordinates), or 404 when nothing was found.

### GET `/api/rest/geo/{lat},{lng}/g`

Reverse geocoding using Google Maps API.

### GET `/api/rest/geo/{lat},{lng}/n`

Reverse geocoding using Nominatim API.

### GET `/api/rest/geo/{lat},{lng}/m`

Reverse geocoding using MapBox API.

## Vehicle endpoint

Requires authorization, registration and confirmed terms.

### GET `/api/rest/vehicle/{plateId}`

Editor preview of what the server stores on save (`\vehicle_info\refresh`, parkowanie.info, cached): returns
`{plateId, brand, model, grossVehicleWeight, isHeavyVehicle, warning}`, or `{}` when the plate is unknown or the
source is unavailable. The make/model is never written into `comment`: the server renders it after the plate
("ZS12331 (pojazd marki Volvo XC60)").

## Vision endpoints

Requires authorization, registration and confirmed terms (same as Application endpoints).
Rate-limited per user (`VISION_RATE_MAX` per `VISION_RATE_WINDOW`, default 60/hour) — exceeding
it returns 429.

### POST `/api/rest/vision/candidate`

LLM analysis of one report candidate's photos for the UD Pro mobile app: role classification
(context/car/third/unusable), violation markers, and license-plate OCR with an automatic
retry-crop (unreadable plate) and a second-opinion bbox verification, all done server-side
(see `src/inc/integrations/Vision.php`) — the client never talks to the LLM provider directly.

POST body (JSON):

  * `reportId` (optional string) — for logging/correlation only.
  * `photos` (required array, max `VISION_MAX_PHOTOS`, default 12) — each:
    * `photoId` (required string) — opaque client id, echoed back.
    * `photo_index` (required int) — must be a contiguous `0..n-1` set across the request.
    * `image` (string, optional) — `data:image/jpeg;base64,...` or `data:image/png;base64,...`,
      max `VISION_MAX_PHOTO_BYTES` decoded bytes per photo (default 800kB), max
      `VISION_MAX_TOTAL_BYTES` decoded bytes total (default 6MB).
      **Omit `image` and pass the `photoId` returned by `POST /api/rest/photos/`** to analyse a staged photo
      without sending it again: bytes come from the server (404 when unknown/expired) and the plate recognition
      result is stored with the photo, so ALPR runs once per photo (also reused later by `POST .../image`).

Response (200, JSON):

```jsonc
{
  "reportId": "R014", "schema": 5, "model": "gpt-4o-mini",
  "photos": [{
    "photo_index": 0, "photoId": "ph_abc123", "role": "context|car|third|unusable",
    "quality": 0.0,
    "car":   { "present": true, "bbox": [0,0,0,0], "desc": null },
    "plate": { "readable": true, "text": "ZS228FC", "bbox": [0,0,0,0],
               "from_crop": true, "plate_check": "unverified-box" },
    "markers": ["sidewalk_parking"], "suggested_category": 26, "category_confidence": 0.8,
    "plate_verified": true
  }],
  "usage": { "prompt_tokens": 0, "completion_tokens": 0, "calls": 0, "cost_usd": 0.0 },
  "warnings": []
}
```

`from_crop`/`plate_check`/`plate_verified` are only present when applicable. `schema` mirrors
`SCHEMA` in the app's `src/lib/vision.ts` — the app gates its local analysis cache on it and
should treat a mismatch as "uncacheable, but usable".

Errors: 400 (bad body/size/indices), 415 (unsupported image format), 429 (rate limit), 502
(model/upstream failed after internal retries).

## Configuration endpoints

No authorization needed.

### GET `/api/rest/config/`

Returns a list of all available configuration files.

### GET `/api/rest/config/categories`

Returns a dictionary of application categories.

### GET `/api/rest/config/terms`

Returns the rendered terms of service in JSON format.

### GET `/api/rest/config/{name}`

Returns a specific dictionary/configuration file.

Valid `{name}` values:
  * `badges`
  * `categories`
  * `extensions`
  * `levels`
  * `patronite`
  * `sm`
  * `statuses`
  * `stop-agresji`
  * `terms`

## MCP server (Model Context Protocol)

### GET `/mcp`

Human-facing landing page (browser, routed to the main app — see the `$mcp_index` nginx map).
Anonymous or logged-in-with-no-connections: connect instructions only. Logged in with connected
apps: the list (with a revoke action at `POST /mcp/revoke`) followed by the same instructions.

### POST `/mcp`

Streamable HTTP MCP endpoint. Requires an OAuth 2.1 bearer access token (see below); on a
missing/invalid token it returns `401` with a `WWW-Authenticate: Bearer resource_metadata="…"`
header pointing at the protected-resource metadata.

The server lets an assistant read reports, record the authority's response
(`update_report_status`), save a report's **private** annotations — the case number and a
private note (`set_report_notes`) — check whether a plate has been reported before without
creating anything (`check_plate`), and create pre-filled **drafts** (`create_report_draft`) for
the user to finish and send themselves. It cannot send reports, edit already-sent content, or
fetch binary assets (images/PDF/ZIP). Tools reject unknown arguments (`additionalProperties:
false`) rather than silently dropping them, and domain failures come back as readable tool errors
(e.g. an illegal status transition or an unknown report id), not opaque internal errors.

Tools:

  * `list_reports` — scope `reports:read`. Params: `status` (enum: `all`, `allWithDrafts`, or a
    specific status id; default `all`), `limit` (default 50). Returns `{ "reports": [...] }`.
    `all` returns sent reports and **excludes drafts**; use `allWithDrafts` to include drafts.
  * `get_report` — scope `reports:read`. Param: `reportId`.
  * `check_plate` — scope `reports:read`. Param: `plateId`. Returns
    `{ plateId, appsCnt, usersCnt, sharedHistory, reports: [...] }` without creating a draft or
    report. `appsCnt`/`usersCnt` always reflect every user's reports for the plate. `reports`
    only lists other users' reports once the plate has `sharedHistory: true` (at least 2 reports
    from at least 2 different users — the same threshold the public plate page uses); below that
    it lists only the signed-in user's own matching reports. Each entry has `date`, `status`,
    `statusLabel`, `categoryInfo`, `recipient` (`name`/`shortName`/`isPolice`), and `isOwn`; only
    the caller's own entries additionally carry `reportId`, `number`, and (when set and not
    encrypted) `caseNumber`. No email addresses or images are ever included.
  * `update_report_status` — scope `reports:status:write`. Params: `reportId`, `status` (enum of
    recordable outcomes: `confirmed-sm`, `confirmed-fined`, `confirmed-instructed`,
    `confirmed-ignored`, `confirmed-complaint`, `archived`). The transition is validated by the
    domain layer.
  * `set_report_notes` — scope `reports:notes:write`. Params: `reportId`, and at least one of
    `caseNumber` (authority case number) / `privateNote`. Both are private to the user and are
    never sent to the authorities; each given value overwrites the current one.
  * `list_categories` — scope `reports:read`. No params. Returns `{ "categories": [...] }`, each
    with `id`, `title`, `formal`, `law`, `fine` (PLN), `demeritPoints`.
  * `create_report_draft` — scope `reports:create`. All params optional: `category` (id, from
    `list_categories`), `extensions` (additional category ids stacked on the primary one),
    `witness` (whether the reporter witnessed the moment of parking), `destination` (`sm` or
    `police` — the authority the draft is addressed to; defaults to the user's saved preference),
    `plateId`, `description`, `address`, `lat`, `lng`, `datetime` (ISO 8601), and up to three
    images — `carImage`, `contextImage`, `thirdImage` — each a base64 data URI (JPEG/PNG, ≤ 2 MB,
    no URL fetching). Creates a `draft` and returns `{ report, editUrl }` — the report carries the
    chosen `destination` (`sm`/`police`); the
    user opens `editUrl` to review the draft (adding anything that wasn't supplied) and send. The
    server never sends the report itself.

    Location precedence, mirroring the web form: (1) explicit `lat`/`lng` win for the pin; (2) the
    `carImage`'s EXIF GPS fills them only when both are entirely omitted; (3) a bare `address`
    string is forward-geocoded (Nominatim search, requires a locality — "street, city") to obtain
    whichever of `lat`/`lng` is still missing, so the editor's map can center on it. Once
    coordinates are known (from any of the above), they're reverse-geocoded via Nominatim to fill
    the structured fields (`city`, `voivodeship`, `postcode`, `county`, `municipality`, `district`),
    resolve the recipient unit (`recipientInfo` + stored `smCity`), and pre-resolve both editor
    radio options into `destinationOptions` (`sm`/`police` with `name`, `address`, `email`,
    `isPolice`). A caller-supplied `address` string is kept as the display address (the geocoded
    full string goes to `addressGPS`). Geocoding failure is non-fatal: the caller's data alone is
    kept. A fresh draft's empty `address` stays an object (`{}`), never a list.

    Supplying both `address` and `lat`/`lng` that resolve to places more than ~500 m apart is
    rejected with a tool error instead of silently keeping the address text while the pin and
    recipient follow the coordinates — send one or the other. A conflict can only be detected when
    the address is itself geocodable; an unmappable or locality-less address is not compared and
    the coordinates are used as given.

    When a plate is known (the `plateId` param or ALPR recognition of the `carImage`), the draft's
    description is enriched like the web editor does: the make/model line (`Pojazd marki …`) and —
    when available — the gross-weight note are looked up at parkowanie.zbiorkom.live and appended
    (deduplicated), so a draft created via MCP carries the same vehicle info a web draft would. The
    lookup is non-fatal: no data keeps the description as supplied.

Every returned report expands its category into `categoryInfo` (`id`, `title`, `formal` wording,
`law`, `fine` in PLN, `demeritPoints`) alongside the raw `category` number, and includes its
`destination` (`sm`/`police`). Once the report has a resolved `smCity`, it also carries the current
`recipientInfo` (`name`, `address`, `email`, `isPolice`) and both pre-resolved editor choices in
`destinationOptions`. Status semantics are **not** repeated per report: the meaning of each
`status` id and its allowed transitions are provided once, as a legend in the server `instructions`
(generated from `statuses.json`). This keeps list responses lean and puts static enum documentation
where clients reliably surface it to the model (instructions), rather than in the output schema
(which clients use mainly for validation).

## OAuth 2.1 provider

Authorization-code grant with PKCE (S256), refresh tokens, and Dynamic Client Registration.
Tokens are opaque (stored as SHA-256 hashes), audience-bound to the `/mcp` resource. Scopes:
`reports:read`, `reports:status:write`, `reports:notes:write`, `reports:create`. The consent step
reuses the existing Firebase login.

### GET `/.well-known/oauth-authorization-server`

RFC 8414 authorization-server metadata.

### GET `/.well-known/oauth-protected-resource` and `/.well-known/oauth-protected-resource/mcp`

RFC 9728 protected-resource metadata (served at both the bare and resource-suffixed paths).

### POST `/oauth/register`

RFC 7591 Dynamic Client Registration. Public clients only (no secret). JSON body:

  * `redirect_uris` (required, array; matched exactly at authorization)
  * `client_name` (optional)

### GET/POST `/oauth/authorize`

Authorization endpoint. Unauthenticated users are sent through the Firebase login, then a
consent screen; approval redirects back to the client's `redirect_uri` with `code` + `state`.
The consent screen lists each requested scope as a checkbox (checked by default); the user may
uncheck some to grant only a subset, and the issued token carries only the granted scopes.
Unchecking everything (or denying) redirects back with `error=access_denied`.

### POST `/oauth/token`

Token endpoint. Grant types: `authorization_code`, `refresh_token`.

### POST `/oauth/revoke`

RFC 7009 token revocation.
