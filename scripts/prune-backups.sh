#!/usr/bin/env sh
set -eu

: "${BACKUP_DIR:?BACKUP_DIR is required}"

echo "Pruning old backups..."

DAILY_AGE=7
WEEKLY_AGE=28
PRUNED=0

for SUBDIR in db attachments; do
    DAILY_DIR="$BACKUP_DIR/$SUBDIR/daily"
    WEEKLY_DIR="$BACKUP_DIR/$SUBDIR/weekly"

    if [ -d "$DAILY_DIR" ]; then
        COUNT=$(find "$DAILY_DIR" -type f -mtime +$DAILY_AGE -print -delete 2>/dev/null | wc -l | tr -d ' ')
        PRUNED=$((PRUNED + COUNT))
    fi

    if [ -d "$WEEKLY_DIR" ]; then
        COUNT=$(find "$WEEKLY_DIR" -type f -mtime +$WEEKLY_AGE -print -delete 2>/dev/null | wc -l | tr -d ' ')
        PRUNED=$((PRUNED + COUNT))
    fi
done

echo "Pruning complete. Removed ${PRUNED} file(s)."
