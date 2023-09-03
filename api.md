## User related endpoints

### GET `get-user`

Requires authorization. Returns current user data.

### POST `register-user`

Requires authorization. Creates new user with given data.

POST params:

  * `name`
  * `address`
  * `msisdn` (optional)
  * `exposeData` (optional, defaults 'N')
        
### POST `update-user`

Requires authorization. Updates current user data.

POST params:

  * `name`
  * `address`
  * `msisdn` (optional)
  * `exposeData` (optional, defaults 'N')
  * `stopAgresji` (optional, default 'SM')
  * `autoSend` (deprecated, default 'Y')
  * `myAppsSize` (optional, default 200)

### GET `user-apps`

Requires authorization. Returns user's applications.

GET params:

  * `status` (optional, default 'all')
  * `search` (optional, default '%')
  * `limit` (optional, default 0)
  * `offset` (optional, default 0)

## Application related endpoints

### POST `upload-app-image`

Requires authorization. Uploads an image to the given app id.

POST params:

  * `pictureType` (carImage|contextImage)
  * `id`

FILES params:

  * `image`

### POST `set-app-status`

Requires authorization. Changes application status.

POST params:

  * `status` (see GET statuses)
  * `id`

### POST `send-app`

Requires authorization. Sends an email to police/city-guards station.

POST params:

  * `id`

### GET `get-app`

No authorization needed. Returns application data by id.

GET params:

  * `id`

### POST `update-app`

Requires authorization.

POST params:

  * `id`
  * `plateId` 
  * `address`
  * `city`
  * `voivodeship`
  * `country`
  * `district`
  * `dtFromPicture` (1|0)
  * `datetime`
  * `latlng`
  * `comment` (optiona, default '')
  * `category`
  * `extensions` (optional, default none), example formats "6,7", "6", "", missing

### GET `geo-to-address`

No authorization needed.

GET params:

  * `lat`
  * `lng`

## Dictionaries

### GET `config-sm`

No authorization needed. Return a dictionary of registered city-guard stations.

### GET `config-categories`

No authorization needed. Return a dictionary of application categories.

### GET `config-extensions`

No authorization needed. Return a dictionary of application extensions.

### GET `config-statuses`

No authorization needed. Return a dictionary of application statuses.

### GET `config-stop-agresji`

No authorization needed. Return a dictionary of voivodeship police-departments.
    
