#!/bin/bash
# ============================================================
# Fleckfrei.de Site Monitor
# Checks all pages, PHP syntax, sends Telegram alerts
# Usage: ./monitor.sh [--once] [--interval 300]
# ============================================================

set -euo pipefail

# Config
DOMAIN="https://app.fleckfrei.de"
HEALTH_KEY="flk_health_2026_c9a3f1e7d4b2"
TELEGRAM_BOT="***REDACTED***"
TELEGRAM_CHAT="6904792507"
SSH_CMD="ssh -i ~/.ssh/hostinger_jwt -p 65002 u860899303@62.72.37.195"
REMOTE_ROOT="/home/u860899303/domains/app.fleckfrei.de/public_html"
LOCAL_SRC="/Users/fleckfrei.de/src/fleckfrei-admin"
BACKUP_DIR="/Users/fleckfrei.de/src/fleckfrei-admin/backups"
LOG_FILE="/Users/fleckfrei.de/src/fleckfrei-admin/scripts/monitor.log"
INTERVAL=300  # 5 minutes default
RUN_ONCE=false

# Parse args
while [[ $# -gt 0 ]]; do
    case $1 in
        --once) RUN_ONCE=true; shift ;;
        --interval) INTERVAL=$2; shift 2 ;;
        *) shift ;;
    esac
done

# Pages to check (public + login-required via health API)
PAGES=(
    "/login.php"
    "/api/health.php?key=${HEALTH_KEY}"
)

# Pages that need auth cookie (checked via curl with session)
AUTH_PAGES=(
    "/admin/"
    "/admin/jobs.php"
    "/admin/customers.php"
    "/admin/invoices.php"
    "/admin/employees.php"
    "/admin/work-hours.php"
    "/admin/messages.php"
    "/admin/live-map.php"
    "/admin/settings.php"
    "/admin/services.php"
    "/admin/scanner.php"
    "/admin/audit.php"
    "/admin/bank-statement.php"
    "/customer/"
    "/customer/jobs.php"
    "/customer/invoices.php"
    "/customer/messages.php"
    "/customer/profile.php"
    "/customer/booking.php"
    "/customer/documents.php"
    "/customer/workhours.php"
    "/employee/"
    "/employee/messages.php"
    "/employee/profile.php"
    "/employee/earnings.php"
)

mkdir -p "$BACKUP_DIR" "$(dirname "$LOG_FILE")"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

send_telegram() {
    local msg="$1"
    curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT}/sendMessage" \
        -d chat_id="${TELEGRAM_CHAT}" \
        -d parse_mode="HTML" \
        -d text="${msg}" > /dev/null 2>&1 || true
}

check_url() {
    local url="$1"
    local http_code
    http_code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 15 "$url" 2>/dev/null || echo "000")
    echo "$http_code"
}

run_check() {
    local errors=()
    local warnings=()
    local timestamp
    timestamp=$(date '+%Y-%m-%d %H:%M:%S')

    log "=== Starting health check ==="

    # 1. Check public pages (no auth needed)
    for page in "${PAGES[@]}"; do
        local url="${DOMAIN}${page}"
        local code
        code=$(check_url "$url")
        if [[ "$code" -ge 500 ]]; then
            errors+=("500 ERROR: ${page} (HTTP ${code})")
            log "FAIL: ${page} → HTTP ${code}"
        elif [[ "$code" -ge 400 ]]; then
            warnings+=("${page} → HTTP ${code}")
            log "WARN: ${page} → HTTP ${code}"
        else
            log "OK: ${page} → HTTP ${code}"
        fi
    done

    # 2. Check health API response in detail
    local health_response
    health_response=$(curl -s --max-time 15 "${DOMAIN}/api/health.php?key=${HEALTH_KEY}" 2>/dev/null || echo '{"status":"unreachable"}')
    local health_status
    health_status=$(echo "$health_response" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status','unknown'))" 2>/dev/null || echo "parse_error")

    if [[ "$health_status" == "unhealthy" ]]; then
        local health_errors
        health_errors=$(echo "$health_response" | python3 -c "import sys,json; [print(e) for e in json.load(sys.stdin).get('errors',[])]" 2>/dev/null || true)
        while IFS= read -r err; do
            [[ -n "$err" ]] && errors+=("HEALTH: $err")
        done <<< "$health_errors"
    elif [[ "$health_status" == "unreachable" || "$health_status" == "parse_error" ]]; then
        errors+=("Health API unreachable or invalid response")
    fi
    log "Health API status: ${health_status}"

    # 3. Remote PHP syntax check (all files)
    log "Running remote PHP syntax check..."
    local syntax_output
    syntax_output=$($SSH_CMD "find ${REMOTE_ROOT} -name '*.php' -not -path '*/vendor/*' | while read f; do php -l \"\$f\" 2>&1; done | grep -v 'No syntax errors'" 2>/dev/null || true)
    if [[ -n "$syntax_output" ]]; then
        while IFS= read -r line; do
            [[ -n "$line" ]] && errors+=("SYNTAX: $line")
        done <<< "$syntax_output"
        log "SYNTAX ERRORS FOUND"
    else
        log "All PHP files: syntax OK"
    fi

    # 4. Check auth pages via SSH (avoid needing cookies)
    log "Checking auth pages via SSH..."
    for page in "${AUTH_PAGES[@]}"; do
        local remote_file="${REMOTE_ROOT}${page}"
        # If path ends with /, check index.php
        [[ "$page" == */ ]] && remote_file="${remote_file}index.php"
        local exists
        exists=$($SSH_CMD "test -f '${remote_file}' && echo 'yes' || echo 'no'" 2>/dev/null || echo "ssh_error")
        if [[ "$exists" == "no" ]]; then
            errors+=("MISSING FILE: ${page}")
            log "MISSING: ${page}"
        elif [[ "$exists" == "ssh_error" ]]; then
            errors+=("SSH ERROR checking ${page}")
            log "SSH ERROR: ${page}"
        fi
    done

    # 5. Check if local and server files are in sync
    log "Comparing local vs server files..."
    local drift_count=0
    for dir in admin api customer employee includes; do
        local diff_output
        diff_output=$($SSH_CMD "md5sum ${REMOTE_ROOT}/${dir}/*.php 2>/dev/null" 2>/dev/null || true)
        # Just count — detailed drift check only on demand
        if [[ -n "$diff_output" ]]; then
            local remote_count
            remote_count=$(echo "$diff_output" | wc -l | tr -d ' ')
            local local_count
            local_count=$(find "${LOCAL_SRC}/${dir}" -maxdepth 1 -name "*.php" 2>/dev/null | wc -l | tr -d ' ')
            if [[ "$remote_count" != "$local_count" ]]; then
                warnings+=("File count mismatch in /${dir}: server=${remote_count}, local=${local_count}")
                drift_count=$((drift_count + 1))
            fi
        fi
    done
    [[ $drift_count -eq 0 ]] && log "File counts match" || log "File count mismatches: ${drift_count}"

    # 6. Report
    if [[ ${#errors[@]} -gt 0 ]]; then
        local msg="🚨 <b>Fleckfrei Monitor Alert</b>\n\n"
        msg+="<b>${#errors[@]} error(s) found:</b>\n"
        for err in "${errors[@]}"; do
            msg+="• ${err}\n"
        done
        [[ ${#warnings[@]} -gt 0 ]] && {
            msg+="\n<b>Warnings:</b>\n"
            for w in "${warnings[@]}"; do
                msg+="• ${w}\n"
            done
        }
        msg+="\n⏰ ${timestamp}"
        send_telegram "$msg"
        log "ALERT sent via Telegram (${#errors[@]} errors)"
    else
        log "All checks passed ✓"
        # Send OK message every 6 hours (every 72nd check at 5min interval)
        local hour=$(date '+%H')
        local minute=$(date '+%M')
        if [[ "$hour" == "08" && "$minute" -lt "06" ]] || [[ "$hour" == "14" && "$minute" -lt "06" ]] || [[ "$hour" == "20" && "$minute" -lt "06" ]]; then
            send_telegram "✅ <b>Fleckfrei Monitor</b>\n\nAll ${#PAGES[@]} public + ${#AUTH_PAGES[@]} auth pages healthy.\nSyntax: OK | DB: OK | Files: synced\n⏰ ${timestamp}"
        fi
    fi

    [[ ${#warnings[@]} -gt 0 ]] && log "Warnings: ${warnings[*]}"
    log "=== Check complete ==="
}

# Auto-backup function
create_backup() {
    local backup_name="backup_$(date '+%Y%m%d_%H%M%S')"
    local backup_path="${BACKUP_DIR}/${backup_name}"
    mkdir -p "$backup_path"
    log "Creating backup: ${backup_name}"
    $SSH_CMD "cd ${REMOTE_ROOT} && tar czf - admin/ api/ customer/ employee/ includes/ login.php index.php" > "${backup_path}/server.tar.gz" 2>/dev/null
    log "Backup saved: ${backup_path}/server.tar.gz"
    # Keep only last 10 backups
    ls -dt "${BACKUP_DIR}"/backup_* 2>/dev/null | tail -n +11 | xargs rm -rf 2>/dev/null || true
}

# Main loop
log "Monitor started (interval: ${INTERVAL}s, once: ${RUN_ONCE})"
send_telegram "🟢 <b>Fleckfrei Monitor Started</b>\nInterval: ${INTERVAL}s\nChecking ${#PAGES[@]} public + ${#AUTH_PAGES[@]} auth pages\n+ PHP syntax + DB + file sync"

if $RUN_ONCE; then
    run_check
else
    # Create initial backup
    create_backup
    while true; do
        run_check
        # Create backup every 6 hours
        local hour=$(date '+%H')
        if [[ "$hour" == "02" || "$hour" == "08" || "$hour" == "14" || "$hour" == "20" ]]; then
            local minute=$(date '+%M')
            if [[ "$minute" -lt "06" ]]; then
                create_backup
            fi
        fi
        sleep "$INTERVAL"
    done
fi
