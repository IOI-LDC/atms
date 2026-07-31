#!/usr/bin/env sh
set -eu

umask 077

: "${BACKUP_DIR:?BACKUP_DIR is required}"

DB_NAME="${DB_DATABASE:-atms}"
DB_USER="${DB_USERNAME:-atms}"
DIR="$BACKUP_DIR/db/daily"
TIMESTAMP=$(date -u +%Y-%m-%dT%H-%M-%S)
FILENAME="atms-pg-${TIMESTAMP}.dump"
FILEPATH="$DIR/$FILENAME"

mkdir -p "$DIR"

echo "Backing up PostgreSQL database '$DB_NAME'..."

docker compose exec -T postgres pg_dump -Fc -U "$DB_USER" -d "$DB_NAME" > "$FILEPATH"

if [ ! -s "$FILEPATH" ]; then
    echo "Error: pg_dump produced empty or missing file" >&2
    exit 1
fi

SIZE=$(wc -c < "$FILEPATH" | tr -d ' ')
SHA256=$(sha256sum "$FILEPATH" | cut -d' ' -f1)

cat > "${FILEPATH}.manifest" <<EOF
filename=${FILENAME}
size=${SIZE}
sha256=${SHA256}
timestamp=${TIMESTAMP}Z
type=db
EOF

echo "PostgreSQL backup complete:"
echo "  File: ${FILEPATH}"
echo "  Size: ${SIZE} bytes"
echo "  SHA256: ${SHA256}"
