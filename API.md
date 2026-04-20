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
