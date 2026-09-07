#!/bin/bash
# nginx-upstream-monitor.sh
# Counts upstream errors in nginx error log and pushes to Netdata via statsd.
# Run via cron every 60s: * * * * * /home/szn/bin/nginx-upstream-monitor.sh
#
# Efficient: reads ONLY new lines appended since last run (tracks byte offset),
# instead of grepping the whole log each time.
#
# Requires: awk (posix), netcat-openbsd (nc), netdata on localhost:8125 (statsd)

STATSD_HOST="127.0.0.1"
STATSD_PORT="8125"
LOG="/var/log/nginx/uprzejmiedonosze/error.log"
STATE="$HOME/.cache/nginx-upstream-monitor.state"

[ -f "$LOG" ] || exit 0

# Last processed byte offset (0 on first run / missing state)
prev_offset=0
[ -f "$STATE" ] && prev_offset=$(awk -F= '/^offset=/{print $2}' "$STATE" 2>/dev/null)
[ -z "$prev_offset" ] && prev_offset=0

cur_size=$(stat -c %s "$LOG")

# Handle log rotation / truncation: if file shrank, restart from 0
if [ "$cur_size" -lt "$prev_offset" ]; then
    prev_offset=0
fi

# Count occurrences of each pattern in ONLY the new bytes since last offset
new_buffered=0; new_premature=0; new_disabled=0; new_timeout=0; new_no_live=0
if [ "$prev_offset" -lt "$cur_size" ]; then
    read -r new_buffered new_premature new_disabled new_timeout new_no_live \
        <<< "$(tail -c +$((prev_offset + 1)) "$LOG" 2>/dev/null | awk '
            { 
                if (index($0, "buffered to a temporary file")) b++;
                if (index($0, "prematurely closed")) p++;
                if (index($0, "upstream server temporarily disabled")) d++;
                if (index($0, "upstream timed out")) t++;
                if (index($0, "no live upstreams")) n++;
            }
            END { printf "%d %d %d %d %d", b, p, d, t, n }
        ')"
fi

# Save new offset
cat > "$STATE" <<EOF
offset=$cur_size
EOF

# Send to statsd as counters (same format as Telemetry.php: "metric:1|c")
# Prefix: uprzejmiedonosze.nginx_upstream — matches existing app job glob
for dim in buffered:$new_buffered premature:$new_premature disabled:$new_disabled timeout:$new_timeout no_live:$new_no_live; do
    name="${dim%%:*}"
    val="${dim##*:}"
    [ "$val" -gt 0 ] && printf "uprzejmiedonosze.nginx_upstream.%s:%d|c\n" "$name" "$val" | nc -u -w1 "$STATSD_HOST" "$STATSD_PORT"
done

exit 0
