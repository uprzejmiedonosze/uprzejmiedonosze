# How to start

## Prerequisites

To start you have to:

1. Have Posix compatible OS (Linux / OSX)
2. Have `Docker` CE installed
3. Have `GIT` installed
4. Have `PHP>=8.2` and `composer` installed
5. Have `node 18.*` installed
6. Have `rsync`, `curl`, `make`, `sed`, `jq`, `md5sum` and `sponge` available
7. (Optional) Have `firebase-tools` installed for Firebase emulator: `npm install -g firebase-tools`

(for `md5sum` on OSX you can either `brew install md5sha1sum` or add `alias md5sum='md5 -r'` to your `.bashrc`)
(for `sponge` on OSX run `brew install moreutils`)


## Cloning

```
$ git clone git@github.com:uprzejmiedonosze/uprzejmiedonosze.git
```

## External services setup

To run the app you will need to set up a few external services:
- Google Firebase setup is required to log into your local environment.
- MapBox API is required to render map contents.
- Google Maps API is required as a fallback geolocation.
- ALPR credentials are required if you want automated plate recognition to work.

### Firebase Authentication

You have two options for Firebase setup:

**Option 1: Firebase Emulator (Recommended for development)**

No Firebase account or credentials needed. Uses Firebase Local Emulator Suite.

1. Install Firebase CLI: `npm install -g firebase-tools`
2. Start emulator: `make emulator-start` (in separate terminal)
3. Access emulator UI at http://localhost:4000
4. Proceed with "Running the app for the first time" below

**Option 2: Real Firebase Project**

You can either create these must-have accounts by yourself OR ask the
maintainer to send you credentials and Firebase config.

### Google Firebase

1. Create a new project in [Firebase Console](https://console.firebase.google.com/)
2. Configure Authentication in `Build > Authentication` tab. Enable Email/Password and
   Google sign-in providers.A
3. (optional) configure custom domain in `Authentication > Settings > Authorised domains`.
4. Visit your project settings and add an Web app (`</>` icon). No hosting is required.
5. Copy the generated `firebaseConfig` and place it in `getFirebaseConfig()` in `src/js/firebase.js`. 
6. Visit the `Service accounts > Firebase Admin SDK` tab and press `Generate new private key`. Put the downloaded key in `localhost-firebase-adminsdk.json` file.
7. Visit the `Sign-in method > google > Web SDK configuration > Web Client ID`. Copy the Client ID and put it in `getClientId()` function in `src/js/firebase.js`.

### Other credentials

Create a new `config.php` from a `config.dev.php` template. 

These are optional. Application will run without them, but won't be able to read license plates or render map contents.

To obtain new credentials:
- `PLATERECOGNIZER_SECRET` - after registering at <https://platerecognizer.com/>. Free 2500 lookups per month.
- `OPENALPR_SECRET_x` - <https://www.openalpr.com/>, $40/month starter plan.
- `MAPBOX_API_TOKEN` - after registering at <http://mapbox.com/>. Free tier available.
- `GOOGLE_MAPS_API_TOKEN` - optional, fallback geolocation, to be obtained at https://console.cloud.google.com/ on the already-created firebase project.
- `OPENAI_API_KEY` - optional, required for /generator.html, to be obtained at <https://platform.openai.com/>.
- `OPENAI_PROJECT` - same as above, to be obtained at <https://platform.openai.com/settings/organization/projects>. You may also need to verify your account at <https://platform.openai.com/settings/organization/general>.


## Running the app for the first time

Enter the repository folder and download PHP dependencies.

```
$ cd uprzejmiedonosze
$ composer update
$ npm install
```

**If using Firebase Emulator (Option 1 - Auto-start):**

```
$ USE_EMULATOR=1 make dev-run
```

This automatically starts the Firebase emulator and then builds/runs Docker.

**If using Firebase Emulator (Option 2 - Manual):**

Start the emulator in a separate terminal (keep it running):

```
$ make emulator-start
```

**Then compile and run the app:**

```
$ make dev-run
```

If there is no error in the terminal, you should be able to run:

```
$ open http://127.0.0.1
```

To refresh the sources on the docker image make:

```
$ make dev
```

**Emulator commands:**
- `USE_EMULATOR=1 make dev-run` - Auto-start emulator + Docker (recommended)
- `make emulator-start` - Start Firebase Auth emulator manually (port 9099)
- `make emulator-stop` - Stop Firebase emulator
- `make emulator-ui` - Open emulator UI at http://localhost:4000

## Comments

Docker image has three folders installed. Two of them are copied after each docker image build:

`db` – database file with pre-filled data with one user and two applications

`cdn2` – new CDN schema (each user has its own folder)

On the other hand folder `export` created after `make dev-run` (and updated after `make dev`) is a mounted volume inside the docker image (under `/var/www/uprzejmiedonosze.net/webapp`).

There is an ugly hack in `/src/inc/firebase.php` which maps all logged-in users into one (pre-filled) account `e@nieradka.net`. This means that if you log in to your dev environment using your Google account you will still be active as `e@nieradka.net` having two applications ready.

Every time you restart your Docker container all the data will be wiped out.

## Working with sources

1. Play with sources
2. Run `make dev`
3. Refresh website

## Troubleshooting 

Looking for logs? Run:

```
$ make -C docker shell
docker# tail /var/log/uprzejmiedonosze.net/error.log # nginx debug log (very verbose)
docker# tail /var/log/uprzejmiedonosze.net/access.log # nginx access log (not very useful)
docker# tail /var/log/uprzejmiedonosze.net/localhost.log # application log (quite useful)
```

Use `logger()` function to write to `/var/log/uprzejmiedonosze.net/localhost.log`.

Want to copy files from Docker image to host or vice versa? Use hosts `export` director as a proxy. Whatever you put there, it will be available inside the Docker image under `/var/www/uprzejmiedonosze.net/webapp`. It works both directions.
