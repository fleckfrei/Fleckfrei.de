#!/bin/bash
# ============================================================
# Safe Deploy Script — Fleckfrei.de
# Validates PHP syntax BEFORE uploading, creates backup, deploys via SCP
# Usage: ./deploy.sh <file_or_dir> [--no-backup]
# Examples:
#   ./deploy.sh admin/jobs.php
#   ./deploy.sh admin/
#   ./deploy.sh .                    # deploy everything
# ============================================================

set -euo pipefail

SSH_KEY="$HOME/.ssh/hostinger_jwt"
SSH_PORT=65002
SSH_USER="u860899303"
SSH_HOST="62.72.37.195"
REMOTE_ROOT="/home/u860899303/domains/app.fleckfrei.de/public_html"
LOCAL_SRC="/Users/fleckfrei.de/src/fleckfrei-admin"
BACKUP_DIR="/Users/fleckfrei.de/src/fleckfrei-admin/backups"
TELEGRAM_BOT="***REDACTED***"
TELEGRAM_CHAT="6904792507"
SSH_CMD="ssh -i ${SSH_KEY} -p ${SSH_PORT} ${SSH_USER}@${SSH_HOST}"
SCP_CMD="scp -i ${SSH_KEY} -P ${SSH_PORT}"
NO_BACKUP=false

[[ "${2:-}" == "--no-backup" ]] && NO_BACKUP=true

if [[ -z "${1:-}" ]]; then
    echo "Usage: ./deploy.sh <file_or_dir> [--no-backup]"
    echo "  ./deploy.sh admin/jobs.php"
    echo "  ./deploy.sh admin/"
    echo "  ./deploy.sh ."
    exit 1
fi

TARGET="$1"

send_telegram() {
    curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT}/sendMessage" \
        -d chat_id="${TELEGRAM_CHAT}" -d parse_mode="HTML" -d text="$1" > /dev/null 2>&1 || true
}

echo "🔍 Pre-deploy validation..."

# Collect PHP files to validate
FILES=()
if [[ -f "${LOCAL_SRC}/${TARGET}" ]]; then
    FILES=("${LOCAL_SRC}/${TARGET}")
elif [[ -d "${LOCAL_SRC}/${TARGET}" ]]; then
    while IFS= read -r f; do
        FILES+=("$f")
    done < <(find "${LOCAL_SRC}/${TARGET}" -name "*.php" -not -path "*/vendor/*" -not -path "*/backups/*")
else
    echo "ERROR: ${TARGET} not found in ${LOCAL_SRC}"
    exit 1
fi

# Validate PHP syntax locally
echo "Checking ${#FILES[@]} PHP file(s)..."
ERRORS=()
for f in "${FILES[@]}"; do
    # Use server PHP version via SSH for accurate check
    rel_path="${f#${LOCAL_SRC}/}"
    output=$($SSH_CMD "php -l -" < "$f" 2>&1 || true)
    if echo "$output" | grep -qi "error"; then
        ERRORS+=("$rel_path: $output")
        echo "  ❌ ${rel_path}"
    else
        echo "  ✅ ${rel_path}"
    fi
done

if [[ ${#ERRORS[@]} -gt 0 ]]; then
    echo ""
    echo "❌ DEPLOY BLOCKED — ${#ERRORS[@]} syntax error(s):"
    for e in "${ERRORS[@]}"; do
        echo "  $e"
    done
    send_telegram "❌ <b>Deploy BLOCKED</b>\n\n${#ERRORS[@]} syntax error(s) in: ${TARGET}\n$(printf '• %s\n' "${ERRORS[@]}")"
    exit 1
fi

echo ""
echo "✅ All files pass syntax check"

# Backup before deploy
if ! $NO_BACKUP; then
    BACKUP_NAME="pre_deploy_$(date '+%Y%m%d_%H%M%S')"
    BACKUP_PATH="${BACKUP_DIR}/${BACKUP_NAME}"
    mkdir -p "$BACKUP_PATH"
    echo "📦 Creating backup: ${BACKUP_NAME}"
    if [[ -f "${LOCAL_SRC}/${TARGET}" ]]; then
        # Backup single file from server
        $SCP_CMD "${SSH_USER}@${SSH_HOST}:${REMOTE_ROOT}/${TARGET}" "${BACKUP_PATH}/" 2>/dev/null || true
    else
        # Backup directory from server
        $SSH_CMD "cd ${REMOTE_ROOT} && tar czf - ${TARGET}" > "${BACKUP_PATH}/server.tar.gz" 2>/dev/null || true
    fi
    echo "  Backup saved to ${BACKUP_PATH}"
    # Keep only last 20 backups
    ls -dt "${BACKUP_DIR}"/pre_deploy_* 2>/dev/null | tail -n +21 | xargs rm -rf 2>/dev/null || true
fi

# Deploy
echo ""
echo "🚀 Deploying ${TARGET}..."
if [[ -f "${LOCAL_SRC}/${TARGET}" ]]; then
    $SCP_CMD "${LOCAL_SRC}/${TARGET}" "${SSH_USER}@${SSH_HOST}:${REMOTE_ROOT}/${TARGET}"
    echo "  Deployed: ${TARGET}"
else
    for f in "${FILES[@]}"; do
        rel_path="${f#${LOCAL_SRC}/}"
        $SCP_CMD "$f" "${SSH_USER}@${SSH_HOST}:${REMOTE_ROOT}/${rel_path}"
        echo "  Deployed: ${rel_path}"
    done
fi

# Post-deploy syntax verify on server
echo ""
echo "🔍 Post-deploy verification..."
POST_ERRORS=0
for f in "${FILES[@]}"; do
    rel_path="${f#${LOCAL_SRC}/}"
    result=$($SSH_CMD "php -l '${REMOTE_ROOT}/${rel_path}'" 2>&1 || true)
    if echo "$result" | grep -qi "error"; then
        echo "  ❌ SERVER: ${rel_path} — ROLLING BACK"
        # Rollback from backup
        if ! $NO_BACKUP && [[ -f "${BACKUP_PATH}/${TARGET}" ]]; then
            $SCP_CMD "${BACKUP_PATH}/${TARGET}" "${SSH_USER}@${SSH_HOST}:${REMOTE_ROOT}/${TARGET}"
            echo "  ↩️  Rolled back from backup"
        fi
        POST_ERRORS=$((POST_ERRORS + 1))
    fi
done

if [[ $POST_ERRORS -gt 0 ]]; then
    send_telegram "⚠️ <b>Deploy Rollback</b>\n\n${POST_ERRORS} file(s) failed post-deploy check for: ${TARGET}\nAutomatic rollback applied."
    echo "❌ Deploy had issues — rollback applied"
    exit 1
fi

echo ""
echo "✅ Deploy complete — all files verified on server"
send_telegram "✅ <b>Deploy OK</b>\n\n${#FILES[@]} file(s) deployed: ${TARGET}\nAll syntax checks passed."
