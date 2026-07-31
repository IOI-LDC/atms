#!/usr/bin/env sh
set -eu

umask 077

if [ $# -lt 1 ]; then
    echo "Usage: $0 <archive-file> [--yes]" >&2
    exit 1
fi

ARCHIVE_FILE="$1"
SKIP_CONFIRM="${2:-}"

ATTACHMENTS_VOL=$(docker volume ls --format '{{.Name}}' | grep 'attachments$' | head -1)
: "${ATTACHMENTS_VOL:?Could not find attachments Docker volume}"

if [ ! -f "$ARCHIVE_FILE" ]; then
    echo "Error: file not found: $ARCHIVE_FILE" >&2
    exit 1
fi

case "$ARCHIVE_FILE" in
    *.tar.gz) ;;
    *)
        echo "Error: file must have .tar.gz extension: $ARCHIVE_FILE" >&2
        exit 1
        ;;
esac

if [ "$SKIP_CONFIRM" != "--yes" ]; then
    printf "This will replace ALL attachment files. Continue? [y/N] "
    read -r answer
    case "$answer" in
        [yY]|[yY][eE][sS]) ;;
        *) echo "Aborted."; exit 0 ;;
    esac
fi

echo "Restoring attachments from: $ARCHIVE_FILE"

echo "Clearing existing attachments..."
docker run --rm \
    -v "$ATTACHMENTS_VOL":/data \
    alpine sh -c "find /data -mindepth 1 -delete"

echo "Extracting archive..."
ARCHIVE_DIR=$(cd "$(dirname "$ARCHIVE_FILE")" && pwd)
ARCHIVE_BASE=$(basename "$ARCHIVE_FILE")

docker run --rm \
    -v "$ATTACHMENTS_VOL":/data \
    -v "$ARCHIVE_DIR":/out \
    alpine tar -xzf "/out/$ARCHIVE_BASE" -C /data

FILE_COUNT=$(docker run --rm -v "$ATTACHMENTS_VOL":/data alpine sh -c "find /data -type f | wc -l" | tr -d ' ')

echo ""
echo "Attachments restore complete."
echo "  Files restored: ${FILE_COUNT}"
