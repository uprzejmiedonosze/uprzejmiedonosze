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

The server is **read + record-outcome only**: it lists/reads reports and records the
authority's response. Creating reports, editing their content, saving free-text notes, and
fetching binary assets (images/PDF/ZIP) are intentionally out of scope for now. Tools reject
unknown arguments (`additionalProperties: false`) rather than silently dropping them, and
domain failures come back as readable tool errors (e.g. an illegal status transition or an
unknown report id), not opaque internal errors.

Tools:

  * `list_reports` — scope `reports:read`. Params: `status` (enum: `all`, `allWithDrafts`, or a
    specific status id; default `all`), `limit` (default 50). Returns `{ "reports": [...] }`.
    `all` returns sent reports and **excludes drafts**; use `allWithDrafts` to include drafts.
  * `get_report` — scope `reports:read`. Param: `reportId`.
  * `update_report_status` — scope `reports:status:write`. Params: `reportId`, `status` (enum of
    recordable outcomes: `confirmed-sm`, `confirmed-fined`, `confirmed-instructed`,
    `confirmed-ignored`, `confirmed-complaint`, `archived`). The transition is validated by the
    domain layer.

Every returned report expands its category into `categoryInfo` (`id`, `title`, `formal` wording,
`law`, `fine` in PLN, `demeritPoints`) alongside the raw `category` number. Status semantics are **not**
repeated per report: the meaning of each `status` id and its allowed transitions are provided
once, as a legend in the server `instructions` (generated from `statuses.json`). This keeps list
responses lean and puts static enum documentation where clients reliably surface it to the model
(instructions), rather than in the output schema (which clients use mainly for validation).

## OAuth 2.1 provider

Authorization-code grant with PKCE (S256), refresh tokens, and Dynamic Client Registration.
Tokens are opaque (stored as SHA-256 hashes), audience-bound to the `/mcp` resource. Scopes:
`reports:read`, `reports:status:write`. The consent step reuses the existing Firebase login.

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

### POST `/oauth/token`

Token endpoint. Grant types: `authorization_code`, `refresh_token`.

### POST `/oauth/revoke`

RFC 7009 token revocation.
