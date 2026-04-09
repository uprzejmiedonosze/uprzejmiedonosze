#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")" || exit

LOG=/var/log/uprzejmiedonosze/cleanup.log
mkdir -p "$(dirname "$LOG")"

echo "--- $(date '+%Y-%m-%d %H:%M:%S') ---" >> "$LOG"
/usr/bin/php cleanup.php >> "$LOG" 2>&1
