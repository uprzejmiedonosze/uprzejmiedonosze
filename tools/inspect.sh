#!/bin/bash

SQL="sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite"
PARAM="$@"

function print_app_url() {
	echo "      https://uprzejmiedonosze.net/ud-$1.html $2"
}

function is_app_key() {
	[[ "$1" =~ ^[0-9a-f-]{36}$ || "$1" =~ ^[0-9a-zA-Z]{12}$ ]]
}

function is_appId() {
	[[ "$1" =~ ^[Uu][Dd]/[0-9]+/[0-9]+$ ]]
}

function get_app_json() {
	ssh nieradka.net "${SQL} \"select value from applications where key = '$1' \""
}

function get_app_key_by_id() {
	ssh nieradka.net "${SQL} \"select key from applications where json_extract(value, '$.number') = upper('$1') \""
}

is_app_key "$1" && APPKEY=$1
is_appId "$1" && APPKEY=$(get_app_key_by_id "$1")

if [ ${APPKEY+x} ]; then
	echo "Checking application id ${APPKEY}";
	ssh nieradka.net "${SQL} \"select value from applications where key = '${APPKEY}'\"" | jq '.'
	print_app_url "${APPKEY}"
	exit 0
fi

if [[ ${PARAM} =~ @ ]]; then
	WHERE1="json_extract(value, '$.data.email') = '${PARAM}'"
	WHERE2="json_extract(value, '$.user.email') = '${PARAM}'"
else
	WHERE1="lower(json_extract(value, '$.data.name')) like lower('%${PARAM}%')"
	WHERE2="lower(json_extract(value, '$.user.name')) like lower('%${PARAM}%')"
fi

echo "Checking user ${PARAM}";
ssh nieradka.net "${SQL} \"select value from users where ${WHERE1} \"" | jq 'del(.applications) + {"applications": (.applications | length)}'
echo "Stats:"
ssh nieradka.net "${SQL} \"select value from applications where ${WHERE2} \"" | jq -r '.status' | sort | uniq -c | sort -nr
echo "Last 10 applications:"
for key in $(ssh nieradka.net "${SQL} \"select key, json_extract(value, '$.status') from applications where ${WHERE2} order by json_extract(value, '$.added') desc limit 10 \""); do
	print_app_url ${key%|*} "[${key#*|}]"
done
exit 0

