#!/bin/bash
set -euo pipefail

curl -s -f -o /dev/null 'https://uprzejmiedonosze.net/stats/byYear.csv?sessionless'
curl -s -f -o /dev/null 'https://uprzejmiedonosze.net/stats/byCarBrand.csv?sessionless'
curl -s -f -o /dev/null 'https://uprzejmiedonosze.net/stats/statsByDay.csv?sessionless'

