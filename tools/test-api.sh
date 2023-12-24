#!/bin/bash                                                             
set -euo pipefail

CURL="curl -s"
JWT=sample.jwt.token
AUTH="Authorization: Bearer $JWT"
HOST="http://localhost:8080"
GREEN="\033[00;32m"
RED="\033[0;31m"
BLUE="\033[0;35m"
LIGHT_GRAY="\033[1;30m"
RESET_COLOR="\033[m"

DEBUG="$*"

DB="docker/db/store.sqlite"
cp $DB $DB~
trap "mv $DB~ $DB; echo -e '$RESET_COLOR'; exit" INT TERM EXIT 

function PASS() {
    echo -e "$GREEN"pass"$RESET_COLOR"
}

function FAIL() {
    echo -e "${RED}fail, got $1$RESET_COLOR"
    test "$DEBUG" = "-d" && (echo -e "\n$LIGHT_GRAY$2$RESET_COLOR"; exit 1)
    
}

function testStatus() {
    A="${4:-X-ignore: 1}"
    testOutput "$1" "$2" .status "$3" "$A"
}

function testOutput() {
    local AUTH="${5:-X-ignore: 1}"
    local FORM="${6:-}"

    echo -en " $LIGHT_GRAY$1 $2 $3 == $4...$RESET_COLOR "
    RAW=$($CURL -X $1 $HOST$2 -H "$AUTH" $FORM)
    OUTPUT=$(echo "$RAW" | jq -r $3 || true)
    test "$OUTPUT" = "$4" && PASS || FAIL "$OUTPUT" "$RAW"
}

function getFirstDraft() {
    $CURL $HOST/user/apps?status=draft -H "$AUTH" | jq -r '.[].id'
}

function tGroup() {
    echo -e "\n$BLUE$*$RESET_COLOR"
}

function Test() {
    echo -n "  $*"
}



# reset DB

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
testOutput PATCH "/user" .data.email "test@user" "$AUTH"
Test Create again
testOutput PATCH "/user" .isRegistered "false" "$AUTH"
Test Get user
testOutput GET "/user" .isTermsConfirmed "false" "$AUTH"
Test Get apps
testOutput GET "/user/apps" .error "User is not registered!" "$AUTH"
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
APP_ID=$(getFirstDraft)
Test Failed change status
testOutput PATCH "/app/$APP_ID/status/confirmed" .reason "Odmawiam zmiany statusu z draft na confirmed dla zgłoszenia $APP_ID" "$AUTH"
Test Failed send
testOutput PATCH "/app/$APP_ID/send" .error "Nie mogę wysłać zgłoszenia w statusie draft" "$AUTH"
Test Add image
testOutput POST "/app/$APP_ID/image" .carImage.url "cdn2/3/$APP_ID,ca.jpg" "$AUTH" \
    '--form 'image=@"/Users/szn/dev/webapp/cypress/fixtures/img_p.jpg"' --form 'pictureType="carImage"''

# post /app/{appId}
# patch /app/{appId}/status/{status}
# post /app/{appId}/image

