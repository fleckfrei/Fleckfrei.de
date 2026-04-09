#!/bin/bash
# iCal Auto-Sync Cron Script
# Syncs all active iCal feeds every 30 minutes
#
# Crontab (local/VPS):
#   */30 * * * * /path/to/fleckfrei-admin/scripts/ical-cron.sh
#
# Hostinger hPanel (Advanced > Cron Jobs):
#   Interval: Every 30 minutes
#   Command:  curl -s "https://app.fleckfrei.de/api/ical-import.php?key=***REDACTED***&all=1" -H "User-Agent: FleckfreiCron/1.0"
#
# Alternative: PHP cron on Hostinger
#   /usr/bin/php /home/u123456789/domains/app.fleckfrei.de/public_html/api/ical-import.php all=1 key=***REDACTED***

LOGFILE="$(dirname "$0")/ical-cron.log"
URL="https://app.fleckfrei.de/api/ical-import.php?key=***REDACTED***&all=1"

echo "$(date '+%Y-%m-%d %H:%M:%S') — Starting iCal sync..." >> "$LOGFILE"

RESPONSE=$(curl -s -w "\nHTTP_CODE:%{http_code}" "$URL" -H "User-Agent: FleckfreiCron/1.0" --connect-timeout 15 --max-time 60 2>&1)
HTTP_CODE=$(echo "$RESPONSE" | grep "HTTP_CODE:" | cut -d: -f2)
BODY=$(echo "$RESPONSE" | grep -v "HTTP_CODE:")

if [ "$HTTP_CODE" = "200" ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') — OK ($HTTP_CODE): $BODY" >> "$LOGFILE"
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') — ERROR ($HTTP_CODE): $BODY" >> "$LOGFILE"
fi

# Keep log under 1000 lines
tail -n 1000 "$LOGFILE" > "$LOGFILE.tmp" && mv "$LOGFILE.tmp" "$LOGFILE"
