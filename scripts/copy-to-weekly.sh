#!/usr/bin/env sh
set -eu

: "${BACKUP_DIR:?BACKUP_DIR is required}"

echo "Copying latest daily backups to weekly..."

for SUBDIR in db attachments; do
    DAILY_DIR="$BACKUP_DIR/$SUBDIR/daily"
    WEEKLY_DIR="$BACKUP_DIR/$SUBDIR/weekly"

    [ -d "$DAILY_DIR" ] || continue

    mkdir -p "$WEEKLY_DIR"

    LATEST=""
    for pattern in "$DAILY_DIR"/*.dump "$DAILY_DIR"/*.tar.gz; do
        [ -f "$pattern" ] || continue
        [ -z "$LATEST" ] || [ "$pattern" -nt "$LATEST" ] && LATEST="$pattern"
    done

    if [ -n "$LATEST" ]; then
        cp "$LATEST" "$WEEKLY_DIR/"
        MANIFEST="${LATEST}.manifest"
        if [ -f "$MANIFEST" ]; then
            cp "$MANIFEST" "$WEEKLY_DIR/"
        fi
        echo "  Copied: $(basename "$LATEST")"
    fi
done

echo "Weekly copy complete."
