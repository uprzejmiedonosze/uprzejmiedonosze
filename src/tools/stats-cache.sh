#!/bin/bash
set -euo pipefail

curl -s -o /dev/null 'https://uprzejmiedonosze.net/api/csv.html?file=statsByDay'
curl -s -o /dev/null 'https://uprzejmiedonosze.net/api/csv.html?file=statsByYear'
curl -s -o /dev/null 'https://uprzejmiedonosze.net/api/csv.html?file=statsAppsByCity'
curl -s -o /dev/null 'https://uprzejmiedonosze.net/api/csv.html?file=statsByCarBrand'
