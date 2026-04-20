# REST API Documentation

Base path for all endpoints: `/api/rest`

## User endpoints (`/user`)

Requires authorization.

### GET `/`

Returns current user data.

### PATCH `/`

Creates a new user if it does not exist.

### PATCH `/confirm-terms`

Marks terms of service as confirmed by the current user.

### POST `/`

Updates current user data.

POST params (JSON body):

  * `name`
  * `address`
  * `msisdn` (optional)
  * `edelivery` (optional)
  * `stopAgresji` (optional, default 'SM', can be 'SA')
  * `shareRecydywa` (optional, default 'Y')

### GET `/apps`

Returns user's applications.

GET params:

  * `status` (optional, default 'all')
  * `search` (optional, default '%')
  * `limit` (optional, default 0)
  * `offset` (optional, default 0)

## Application endpoints (`/app`)

Requires authorization.

### POST `/new`

Creates a new, empty application linked to the currently authenticated user and returns the newly created application object.

No POST parameters are required.

### GET `/{appId}`

Returns application data by id.

### POST `/{appId}`

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

### PATCH `/{appId}/status/{status}`

Changes application status.

### POST `/{appId}/image`

Uploads an image to the given app id.

POST params:

  * `carImage` OR `contextImage` (image Data URI)
  * `dateTime` (optional, valid only for `carImage`) application event date and time, in ISO format: "2018-02-02T19:48:10"
  * `lat` (optional)
  * `lng` (optional)

### PATCH `/{appId}/send`

Sends an email with the application to police/city-guards station.

## Geolocation endpoints (`/geo`)

Requires authorization.

### GET `/{lat},{lng}/g`

Reverse geocoding using Google Maps API.

### GET `/{lat},{lng}/n`

Reverse geocoding using Nominatim API.

### GET `/{lat},{lng}/m`

Reverse geocoding using MapBox API.

## Configuration endpoints (`/config`)

No authorization needed.

### GET `/`

Returns a list of all available configuration files.

### GET `/categories`

Returns a dictionary of application categories.

### GET `/terms`

Returns the rendered terms of service in JSON format.

### GET `/{name}`

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
