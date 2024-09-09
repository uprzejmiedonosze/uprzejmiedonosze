#!/bin/bash

test $# -eq 0 && echo "no arguments" && exit 1

ENV=""

if [ -n "${STAGING+x}" ];
then
    ENV="staging."
		echo "Using staging ENV"
fi

SQL="sqlite3 /var/www/${ENV}uprzejmiedonosze.net/db/store.sqlite"

ssh nieradka.net "${SQL} \"select value from users where key = '$@' or json_extract(value, '$.data.name') like lower('%$@%') \"" \
	| jq -r '.data, "number: \(.number)", "appsCount: \(.appsCount)"'
