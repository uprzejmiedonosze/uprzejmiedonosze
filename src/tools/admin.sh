#!/bin/bash
set -euo pipefail

SQL="sqlite3 /var/www/uprzejmiedonosze.net/db/store.sqlite"

function is_app_key() {
	[[ "$1" =~ ^[0-9a-f-]{36}$ || "$1" =~ ^[0-9a-zA-Z]{12}$ ]]
}

function is_appId() {
	[[ "$1" =~ ^[Uu][Dd]/[0-9]+/[0-9]+$ ]]
}

function get_app_json() {
	${SQL} "select value from applications where key = '$1'"
}

function get_app_key_by_id() {
	${SQL} "select key from applications where json_extract(value, '$.number') = upper('$1')"
}

function red() {
	tput setaf 160
	echo "$1"
	tput sgr0
}	

function yesno() {
	red "Apply changes [yes/N]?"
	read ans
	[ "${ans:-N}" = yes ]
}

is_app_key "$1" && APPKEY=$1
is_appId "$1" && APPKEY=$(get_app_key_by_id "$1")

if [ ${APPKEY+x} ]; then
	red "Application id ${APPKEY}:";
	${SQL} "select value from applications where key = '${APPKEY}'" | jq '.' | tee /tmp/_o.json > /tmp/_m.json
	vim /tmp/_m.json
	red "Updated"
	jq < /tmp/_m.json
	if ! diff /tmp/_o.json /tmp/_m.json; then
		yesno && ${SQL} "update applications set value ='$(jq -Mrc . < /tmp/_m.json)' where key = '${APPKEY}'"
	else
		echo 'no changes, ignoring'
	fi
fi

PARAM="$*"
if [[ ${PARAM} =~ @ ]]; then
	${SQL} "select value from users where key = '${PARAM}';" | jq '.' | tee /tmp/_o.json > /tmp/_m.json
	vim /tmp/_m.json
	red "Updated"
	jq < /tmp/_m.json
	if ! diff /tmp/_o.json /tmp/_m.json; then
		yesno && ${SQL} "update users set value ='$(jq .-Mrc < /tmp/_m.json)' where key = '${PARAM}';"
	else
		echo 'no changes, ignoring'
	fi
fi

