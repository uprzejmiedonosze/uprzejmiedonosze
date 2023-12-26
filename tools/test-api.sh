#!/bin/bash                                                             
set -euo pipefail

GREEN="\033[00;32m"
RED="\033[0;31m"
BLUE="\033[0;35m"
LIGHT_GRAY="\033[1;30m"
RESET_COLOR="\033[m"

function usage() { echo -e "$*\nUsage: $(basename "$0") [-v] [-t jwt]"; }
function tGroup() { echo -e "\n$BLUE$*$RESET_COLOR"; }
function Test() { echo -n "  $*"; }
function log() { echo -e "$LIGHT_GRAY$*$RESET_COLOR"; }

JWT="eyJhbGciOiJSUzI1NiIsImtpZCI6IjUyNmM2YTg0YWMwNjcwMDVjZTM0Y2VmZjliM2EyZTA4ZTBkZDliY2MiLCJ0eXAiOiJKV1QifQ.eyJuYW1lIjoiU3p5bW9uIE5pZXJhZGthIiwicGljdHVyZSI6Imh0dHBzOi8vbGgzLmdvb2dsZXVzZXJjb250ZW50LmNvbS9hL0FMbTV3dTFXQ0plUjQzOEd3NnQtUzVUMktXaEJuNkp1V2plSlF2d0VqT2RDTnFjPXM5Ni1jIiwiaXNzIjoiaHR0cHM6Ly9zZWN1cmV0b2tlbi5nb29nbGUuY29tL3Vwcnplam1pZWRvbm9zemUtMTQ5NDYwNzcwMTgyNyIsImF1ZCI6InVwcnplam1pZWRvbm9zemUtMTQ5NDYwNzcwMTgyNyIsImF1dGhfdGltZSI6MTcwMzEwNTIwOCwidXNlcl9pZCI6IjBTemJsNlhmSDhPdDVNTmZjRkVxYllpYUtYWDIiLCJzdWIiOiIwU3pibDZYZkg4T3Q1TU5mY0ZFcWJZaWFLWFgyIiwiaWF0IjoxNzAzNTE5Nzk0LCJleHAiOjE3MDM1MjMzOTQsImVtYWlsIjoic3p5bW9uQG5pZXJhZGthLm5ldCIsImVtYWlsX3ZlcmlmaWVkIjp0cnVlLCJmaXJlYmFzZSI6eyJpZGVudGl0aWVzIjp7Imdvb2dsZS5jb20iOlsiMTAzNDkxODk0MTUwMzIxMTU0NjE5Il0sImVtYWlsIjpbInN6eW1vbkBuaWVyYWRrYS5uZXQiXX0sInNpZ25faW5fcHJvdmlkZXIiOiJnb29nbGUuY29tIn19.SpXqpopC6dXiM0nXuUtKGEyTlxm3aCTok_FSHfockgKdVJOcBURZEtlD26I42rBXACEt6FhOlYi0UlwDnqakNjAMQwc5AoY_87FYEHhGc9D-x0jIsfjjqOyPoayd1bqtgoOPEQDQXTfS1fL1Ycx61Ac_RNgjsjxrL_pl3U3lEQwIumLpOccu2-sqZE0JNDlBkwAPzShx0ITgqfrCxHV0ZLmv5RpGnj7-6zfiw9PwbYRN48GvIOmctcQErmX7DTOmWrmFEU0pqPFO-iFE7rNODfPNFFQA4DhiR9Gaf8Qyydp6Vwy-gDMu3NGxtShX5qntRIgmA8Er28fK6bosTdgYPg"
ENV=local
VERBOSE=0
CURL="curl -s"


while getopts ':vt:h' opt; do
  case "$opt" in
    v)
      VERBOSE=1
      ;;
    t)
      JWT="$OPTARG"
      ENV=staging
      ;;
    h)
      usage; exit 0
      ;;
    :)
      usage option requires an argument.; exit 1
      ;;
    ?)
      usage Invalid command option.; exit 1
      ;;
  esac
done
shift "$((OPTIND -1))"


decode_jwt() {
    local payload
    payload=$(echo -n "$1" | cut -d "." -f 2)
    local len=$((${#payload} % 4))
    if [ $len -eq 2 ]; then payload="$1"'=='
    elif [ $len -eq 3 ]; then payload="$1"'=' 
    fi
    echo "$payload" | tr '_-' '/+' | openssl enc -d -base64 | jq -r .email
}

HOST="localhost:8080"
EMAIL=$(decode_jwt "$JWT")

if [ $ENV = 'staging' ]; then
    log Usign staging API with given JWT token
    ssh nieradka.net "cd /var/www/staging.uprzejmiedonosze.net/db && cp store.sqlite-empty store.sqlite"
    HOST="https://apistaging.uprzejmiedonosze.net"
else
    log Usign localhost API, local DB and memcache
    DB="docker/db/store.sqlite"
    cp $DB $DB~
    MEMCACHED=$(curl localhost:11211 2>&1 | grep -c Fail || true)
    test "$MEMCACHED" -eq 1 && (log "Starting memcached"; memcached &)
    trap 'log Reverting DB and killing memcache; mv $DB~ $DB; echo -e "$RESET_COLOR"; test "$MEMCACHED" -eq 1 && killall memcached; exit' INT TERM EXIT
fi

AUTH="Authorization: Bearer $JWT"

function PASS() { echo -e "$GREEN"pass"$RESET_COLOR"; }

function FAIL() {
    echo -e "${RED}fail, got $1$RESET_COLOR"
    test $VERBOSE -eq 1 && (echo -e "\n$LIGHT_GRAY$2$RESET_COLOR"; exit 1)
}

function testStatus() {
    A="${4:-X-ignore: 1}"
    testOutput "$1" "$2" .status "$3" "$A"
}

function testOutput() {
    local AUTH="${5:-X-ignore: 1}"
    local FORM="${6:-}"

    echo -en " $LIGHT_GRAY$1 $2 $3 == $4...$RESET_COLOR "
    # shellcheck disable=SC2086
    RAW=$($CURL -X "$1" "$HOST$2" -H "$AUTH" $FORM)
    OUTPUT=$(echo "$RAW" | jq -r "$3" || true)
    # shellcheck disable=SC2015
    test "$OUTPUT" = "$4" && PASS || FAIL "$OUTPUT" "$RAW"
}

function getUserNumber() {
    $CURL $HOST/user -H "$AUTH" | jq -r '.number'
}
function getFirstDraftId() {
    $CURL $HOST/user/apps?status=draft -H "$AUTH" | jq -r '.[].id'
}

tGroup Testing static routes

#testStatus GET "/geo/1,1" 500

tGroup Testing edge cases

Test Root 404
testStatus GET "" 404
Test Use no auth
testStatus GET "/user" 400
Test Apps no auth
testStatus GET "/user/apps" 400
Test New app no auth
testStatus POST "/app/new" 400
Test App no auth
testStatus GET "/app/111" 400
Test Config root
testOutput GET "/config" length 8
Test Config statuses
testOutput GET "/config/statuses" .archived.icon minus
Test Config missing
testStatus GET "/config/xx" 404

tGroup Testing auth

Test Nonexist. user
testStatus GET "/user" 404 "$AUTH"
Test Nonexist. app
testStatus GET "/app/111" 404 "$AUTH"
Test Confirm nonexist. user
testStatus PATCH "/user/confirm-terms" 404 "$AUTH"

tGroup Testing scenario new user scenario

Test Create user
testOutput PATCH "/user" .data.email "$EMAIL" "$AUTH"
USER_NUMBER=$(getUserNumber)
Test Create again
testOutput PATCH "/user" .isRegistered "false" "$AUTH"
Test Get user
testOutput GET "/user" .isTermsConfirmed "false" "$AUTH"
Test Get apps
testOutput GET "/user/apps" .error "Nie możesz wykonać tej operacji przed rejestracją" "$AUTH"
Test Register, bad
testOutput POST "/user" .error "Missing required parameter 'address'" "$AUTH" '--form 'name="new-name"''
Test Register
testOutput POST "/user" .data.name "New-Name" "$AUTH" '--form 'name="new-name"' --form 'address="address"''
Test Check registration
testOutput PATCH "/user" .isRegistered "true" "$AUTH"
Test Confirm terms
testOutput PATCH "/user/confirm-terms" .isTermsConfirmed "true" "$AUTH"
Test Check terms
testOutput GET "/user" .isTermsConfirmed "true" "$AUTH"

tGroup Creating apps
Test Empty apps
testOutput GET "/user/apps" length 0 "$AUTH"
Test Create draft
testOutput POST "/app/new" .status draft "$AUTH"
Test Get drafts
testOutput GET "/user/apps?status=draft" length 1 "$AUTH"
APP_ID=$(getFirstDraftId)
Test Failed change status
testOutput PATCH "/app/$APP_ID/status/confirmed" .reason "Odmawiam zmiany statusu z 'draft' na 'confirmed' dla zgłoszenia '$APP_ID'" "$AUTH"
Test Failed send
testOutput PATCH "/app/$APP_ID/send" .error "Nie mogę wysłać zgłoszenia '$APP_ID' w statusie 'draft'" "$AUTH"
Test Add image
testOutput POST "/app/$APP_ID/image" .carImage.url "cdn2/$USER_NUMBER/$APP_ID,ca.jpg" "$AUTH" \
    '--form 'image=@"/Users/szn/dev/webapp/cypress/fixtures/img_p.jpg"' --form 'pictureType="carImage"''

