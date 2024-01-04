#!/bin/bash

ENV=""

if [ -n "${STAGING+x}" ];
then
    ENV="staging."
		echo "Using staging ENV"
fi

SQL="sqlite3 /var/www/${ENV}uprzejmiedonosze.net/db/store.sqlite"
PARAM="$@"

function print_app_url() {
	echo "      https://${ENV}uprzejmiedonosze.net/ud-$1.html $2"
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
	echo "Checking application id ${APPKEY}:";
	ssh nieradka.net "${SQL} \"select value from applications where key = '${APPKEY}'\"" | jq '.'
	print_app_url "${APPKEY}"
	exit 0
fi

if [[ ${PARAM} =~ @ ]]; then
	WHERE1="key = '${PARAM}'"
	WHERE2="email = '${PARAM}'"
elif [[ ${PARAM} =~ [0-9] ]]; then
	echo "Apps with plate id or street ${PARAM}:"
	for key in $(ssh nieradka.net "${SQL} \"select key, json_extract(value, '$.user.email'), json_extract(value, '$.statements.gallery') from applications where lower(json_extract(value, '$.carInfo.plateId')) like lower('%${PARAM}%') or lower(json_extract(value, '$.address.address')) like lower('%${PARAM}%') order by json_extract(value, '$.added') desc limit 100 \""); do
		print_app_url "${key%%|*}" "(${key#*|})"
	done
	exit 0
else
	WHERE1="key like lower('%${PARAM}%')"
	WHERE2="email like lower('%${PARAM}%')"
fi

echo "Checking user ${PARAM}:";
ssh nieradka.net "${SQL} \"select value from users where ${WHERE1} \"" | jq '.'
echo "Stats:"
ssh nieradka.net "${SQL} \"select value from applications where ${WHERE2} \"" | jq -r '.status' | sort | uniq -c | sort -nr
echo "Gallery stats:"
ssh nieradka.net "${SQL} \"select case when json_extract(value, '$.statements.gallery') != 0 then 'zgoda' else 'brak zgody' end as statement, case json_extract(value, '$.addedToGallery') = 0 when 1 then 'odrzucone' when 0 then 'zaakceptowane' else '   ' end as decyzja, count(*) from applications where ${WHERE2} group by 1, 2 ;\"" | tr "|" "	" | sed 's/^/\t/'

echo "Last 10 applications:"
for key in $(ssh nieradka.net "${SQL} \"select key, json_extract(value, '$.status') from applications where ${WHERE2} order by json_extract(value, '$.added') desc limit 10 \""); do
	print_app_url ${key%|*} "[${key#*|}]"
done
exit 0

