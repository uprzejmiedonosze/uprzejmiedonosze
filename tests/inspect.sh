#!/bin/bash

SQL="sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite"
PARAM=$1

function get_app_url() {
	echo -n "      https://uprzejmiedonosze.net/ud-"
	if [[ $1 =~ ^[0-9a-f-]{36}$ || $1 =~ ^[0-9a-zA-Z]{12}$ ]]; then
		echo "$1.html"
	else
		ssh nieradka.net "${SQL} \"select key || '.html' from applications where json_extract(value, '$.number') = '${PARAM}'\""
	fi
}

if [[ ${PARAM} =~ ^[0-9a-f-]{36}$ || $PARAM =~ ^[0-9a-zA-Z]{12}$ ]]; then
	echo "Checking application id ${PARAM}";
	ssh nieradka.net "${SQL} \"select value from applications where key = '${PARAM}'\"" | jq '.'
	get_app_url ${PARAM}
	exit 0
fi

if [[ ${PARAM} =~ ^UD/[0-9]+/[0-9]+$ ]]; then
	echo "Checking appId ${PARAM}";
	ssh nieradka.net "${SQL} \"select value from applications where json_extract(value, '$.number') = '${PARAM}' \"" | jq '.'
	get_app_url ${PARAM}
	exit 0
fi

if [[ ${PARAM} =~ @ ]]; then
	echo "Checking user ${PARAM}";
	ssh nieradka.net "${SQL} \"select value from users where json_extract(value, '$.data.email') = '${PARAM}' \"" | jq 'del(.applications) + {"applications": (.applications | length)}'
	ssh nieradka.net "${SQL} \"select value from applications where json_extract(value, '$.user.email') = '${PARAM}' \"" | jq -r '.status' | sort | uniq -c | sort -nr
	exit 0
fi

echo "Checking user ${PARAM}";
ssh nieradka.net "${SQL} \"select value from users where lower(json_extract(value, '$.data.name')) like lower('%${PARAM}%') \"" | jq 'del(.applications) + {"applications": (.applications | length)}'
echo "Stats:"
ssh nieradka.net "${SQL} \"select value from applications where lower(json_extract(value, '$.user.name')) like ('%${PARAM}%') \"" | jq -r '.status' | sort | uniq -c | sort -nr
echo "Last 10 applications:"
for key in $(ssh nieradka.net "${SQL} \"select key from applications where lower(json_extract(value, '$.user.name')) like ('%${PARAM}%') order by json_extract(value, '$.added') desc limit 10 \""); do
	get_app_url $key
done
exit 0


