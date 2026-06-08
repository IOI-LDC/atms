#!/usr/bin/env sh
set -eu

umask 077

: "${BACKUP_DIR:?BACKUP_DIR is required}"

ATTACHMENTS_VOL=$(docker volume ls --format '{{.Name}}' | grep 'attachments$' | head -1)
: "${ATTACHMENTS_VOL:?Could not find attachments Docker volume}"

DIR="$BACKUP_DIR/attachments/daily"
TIMESTAMP=$(date -u +%Y-%m-%dT%H-%M-%S)
FILENAME="atms-attachments-${TIMESTAMP}.tar.gz"
FILEPATH="$DIR/$FILENAME"

mkdir -p "$DIR"

echo "Backing up attachments volume..."

docker run --rm \
    -v "$ATTACHMENTS_VOL":/data:ro \
    -v "$DIR":/out \
    alpine tar -czf "/out/$FILENAME" -C /data .

SHA256=$(sha256sum "$FILEPATH" | cut -d' ' -f1)
SIZE=$(wc -c < "$FILEPATH" | tr -d ' ')

cat > "${FILEPATH}.manifest" <<EOF
filename=${FILENAME}
size=${SIZE}
sha256=${SHA256}
timestamp=${TIMESTAMP}Z
type=attachments
EOF

echo "Attachments backup complete:"
echo "  File: ${FILEPATH}"
echo "  Size: ${SIZE} bytes"
echo "  SHA256: ${SHA256}"
