## How to start

### Prerequisites

To start you have to:

1. Have Posix compatible OS (Linux / OSX)
2. Have `Docker` CE installed
3. Have `GIT` installed
4. Have `PHP>=8.2` and `composer` installed
5. Have `node 18.*` installed
5. Have `rsync`, `curl`, `make`, `sed`, `jq` and `md5sum` available

(for `md5sum` on OSX you can either `brew install md5sha1sum` or add `alias md5sum='md5 -r'` to your `.bashrc`)

### Running the app for the first time

First clone git repo:

```
$ git clone git@bitbucket.org:uprzejmiedonosze/uprzejmiedonosze.git
```

Enter the repository folder and download PHP dependencies.

```
$ cd uprzejmiedonosze
$ composer update
$ npm install
```

Copy config template. You are going to need real credentials in that file – contact me. But a basic copy is enough to start.

```
$ cp config.dev.php config.php
```

Now compile the app, build a Docker image, and run it simply by:

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

### Comments

Docker image has three folders installed. Two of them are copied after each docker image build:

`db` – database file with pre-filled data with one user and two applications

`cdn2` – new CDN schema (each user has its own folder)

On the other hand folder `export` created after `make dev-run` (and updated after `make dev`) is a mounted volume inside the docker image (under `/var/www/uprzejmiedonosze.net/webapp`).

There is an ugly hack in `/src/inc/firebase.php` which maps all logged-in users into one (pre-filled) account `e@nieradka.net`. This means that if you log in to your dev environment using your Google account you will still be active as `e@nieradka.net` having two applications ready.

Every time you restart your Docker container all the data will be wiped out.

### Working with sources

1. Play with sources
2. Run `make dev`
3. Refresh website

### Troubleshooting 

Looking for logs? Run:

```
$ make -C docker shell
docker# tail /var/log/uprzejmiedonosze.net/error.log # nginx debug log (very verbose)
docker# tail /var/log/uprzejmiedonosze.net/access.log # nginx access log (not very useful)
docker# tail /var/log/uprzejmiedonosze.net/localhost.log # application log (quite useful)
```

Use `logger()` function to write to `/var/log/localhost.log`.

Want to copy files from Docker image to host or vice versa? Use hosts `export` director as a proxy. Whatever you put there, it will be available inside the Docker image under `/var/www/uprzejmiedonosze.net/webapp`. It works both directions.