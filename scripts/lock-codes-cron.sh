#!/bin/bash
# Smart-Lock Access-Code Cleanup Cron
# Revokes expired / completed-job codes on Nuki (and other providers) every 15 minutes.
#
# Crontab (local/VPS):
#   */15 * * * * /path/to/fleckfrei-admin/scripts/lock-codes-cron.sh
#
# Hostinger hPanel (Advanced > Cron Jobs):
#   Interval: Every 15 minutes
#   Command:  curl -s "https://app.fleckfrei.de/api/lock-codes-cleanup.php?key=***REDACTED***" -H "User-Agent: FleckfreiLockCron/1.0"

LOGFILE="$(dirname "$0")/lock-codes-cron.log"
URL="https://app.fleckfrei.de/api/lock-codes-cleanup.php?key=***REDACTED***"

echo "$(date '+%Y-%m-%d %H:%M:%S') — Starting lock-code cleanup..." >> "$LOGFILE"

RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" "$URL" -H "User-Agent: FleckfreiLockCron/1.0" --connect-timeout 15 --max-time 60 2>&1)
HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE:" | cut -d: -f2)
BODY=$(echo "$RESPONSE" | grep -v "HTTP_CODE:")

if [ "$HTTP_CODE" = "200" ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') — OK: $BODY" >> "$LOGFILE"
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') — ERROR ($HTTP_CODE): $BODY" >> "$LOGFILE"
fi

# Keep log under 1000 lines
tail -n 1000 "$LOGFILE" > "$LOGFILE.tmp" && mv "$LOGFILE.tmp" "$LOGFILE"
