# REST API Documentation

*Note: Due to the framework's strict routing (Slim 4), endpoints corresponding to the root of a group **must** include a trailing slash (e.g., `/api/rest/user/` instead of `/api/rest/user`). Missing trailing slashes will result in a 404 Not Found error.*

## User endpoints

Requires authorization.

### GET `/api/rest/user/`

Returns current user data.

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

### GET `/api/rest/user/apps`

Returns user's applications.

GET params:

  * `status` (optional, default 'all')
  * `search` (optional, default '%')
  * `limit` (optional, default 0)
  * `offset` (optional, default 0)

## Application endpoints

Requires authorization.

### POST `/api/rest/app/new`

Creates a new, empty application linked to the currently authenticated user and returns the newly created application object.

No POST parameters are required.

### GET `/api/rest/app/{appId}`

Returns application data by id.

### POST `/api/rest/app/{appId}`

Updates application details.

POST params:

  * `plateId` 
  * `address`
  * `city`
  * `voivodeship`
  * `district`
  * `dtFromPicture` (1|0)
  * `datetime`
  * `lat`
  * `lng`
  * `comment` (optional, default '')
  * `category`
  * `witness`
  * `extensions` (optional, comma-separated list like "6,7")

### PATCH `/api/rest/app/{appId}/status/{status}`

Changes application status.

### POST `/api/rest/app/{appId}/image`

Uploads an image to the given app id.

POST params:

  * `carImage` OR `contextImage` (image Data URI)
  * `dateTime` (optional, valid only for `carImage`) application event date and time, in ISO format: "2018-02-02T19:48:10"
  * `lat` (optional)
  * `lng` (optional)

### PATCH `/api/rest/app/{appId}/send`

Sends an email with the application to police/city-guards station.

## Geolocation endpoints

Requires authorization.

### GET `/api/rest/geo/{lat},{lng}/g`

Reverse geocoding using Google Maps API.

### GET `/api/rest/geo/{lat},{lng}/n`

Reverse geocoding using Nominatim API.

### GET `/api/rest/geo/{lat},{lng}/m`

Reverse geocoding using MapBox API.

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
private note (`set_report_notes`) — and create pre-filled **drafts** (`create_report_draft`) for
the user to finish and send themselves. It cannot send reports, edit already-sent content, or
fetch binary assets (images/PDF/ZIP). Tools reject unknown arguments (`additionalProperties:
false`) rather than silently dropping them, and domain failures come back as readable tool errors
(e.g. an illegal status transition or an unknown report id), not opaque internal errors.

Tools:

  * `list_reports` — scope `reports:read`. Params: `status` (enum: `all`, `allWithDrafts`, or a
    specific status id; default `all`), `limit` (default 50). Returns `{ "reports": [...] }`.
    `all` returns sent reports and **excludes drafts**; use `allWithDrafts` to include drafts.
  * `get_report` — scope `reports:read`. Param: `reportId`.
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

    Location mirrors the web form: coordinates are the source of truth. When `lat`/`lng` are given
    (or absent but readable from the `carImage`'s EXIF GPS), the address is reverse-geocoded via
    Nominatim — the same endpoint the web uses — to fill the structured fields (`city`,
    `voivodeship`, `postcode`, `county`, `municipality`, `district`), resolve the recipient unit
    (`recipientInfo` + stored `smCity`), and pre-resolve both editor radio options into
    `destinationOptions` (`sm`/`police` with `name`, `address`, `email`, `isPolice`). A
    caller-supplied `address` string is kept as the display address (the geocoded full string goes
    to `addressGPS`); a bare address string without coordinates is stored as-is — the web never
    forward-geocodes, and neither does MCP. Geocoding failure is non-fatal: the caller's data alone
    is kept. A fresh draft's empty `address` stays an object (`{}`), never a list.

    When a plate is known (the `plateId` param or ALPR recognition of the `carImage`), the draft's
    description is enriched like the web editor does: the make/model line (`Pojazd marki …`) and —
    when available — the gross-weight note are looked up at parkowanie.zbiorkom.live and appended
    (deduplicated), so a draft created via MCP carries the same vehicle info a web draft would. The
    lookup is non-fatal: no data keeps the description as supplied.

Every returned report expands its category into `categoryInfo` (`id`, `title`, `formal` wording,
`law`, `fine` in PLN, `demeritPoints`) alongside the raw `category` number, and its recipient
authority into `recipientInfo` (`name`, `address`, `email`, `isPolice`) — resolved from the raw
`smCity` key. Status semantics are **not** repeated per report: the meaning of each `status` id
and its allowed transitions are provided once, as a legend in the server `instructions` (generated
from `statuses.json`). This keeps list responses lean and puts static enum documentation where
clients reliably surface it to the model (instructions), rather than in the output schema (which
clients use mainly for validation).

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
