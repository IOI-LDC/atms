#!/usr/bin/env sh
set -eu

if [ $# -lt 1 ]; then
    echo "Usage: $0 <manifest-file>" >&2
    exit 1
fi

MANIFEST="$1"

if [ ! -f "$MANIFEST" ]; then
    echo "Error: manifest not found: $MANIFEST" >&2
    exit 1
fi

echo "Verifying backup from manifest: $MANIFEST"

EXPECTED_SHA=$(grep '^sha256=' "$MANIFEST" | cut -d= -f2)
FILENAME=$(grep '^filename=' "$MANIFEST" | cut -d= -f2)
TYPE=$(grep '^type=' "$MANIFEST" | cut -d= -f2)

MANIFEST_DIR=$(cd "$(dirname "$MANIFEST")" && pwd)
ARCHIVE="${MANIFEST_DIR}/${FILENAME}"

if [ ! -f "$ARCHIVE" ]; then
    echo "FAIL: archive not found: $ARCHIVE" >&2
    exit 1
fi

ACTUAL_SHA=$(sha256sum "$ARCHIVE" | cut -d' ' -f1)

if [ "$ACTUAL_SHA" != "$EXPECTED_SHA" ]; then
    echo "FAIL: SHA256 mismatch" >&2
    echo "  Expected: $EXPECTED_SHA" >&2
    echo "  Actual:   $ACTUAL_SHA" >&2
    exit 1
fi

echo "  SHA256: OK"

if [ "$TYPE" = "db" ]; then
    DB_USER="${DB_USERNAME:-atms}"
    DB_NAME="${DB_DATABASE:-atms}"
    echo ""
    echo "  Database row counts:"
    docker compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c "
SELECT 'users' AS table, COUNT(*) FROM users
UNION ALL SELECT 'assets', COUNT(*) FROM assets
UNION ALL SELECT 'work_orders', COUNT(*) FROM work_orders
UNION ALL SELECT 'maintenance_requests', COUNT(*) FROM maintenance_requests
UNION ALL SELECT 'pm_rules', COUNT(*) FROM pm_rules
ORDER BY 1;
" 2>/dev/null || echo "  (could not query database)"
fi

if [ "$TYPE" = "attachments" ]; then
    ATTACHMENTS_VOL=$(docker volume ls --format '{{.Name}}' | grep 'attachments$' | head -1)
    FILE_COUNT=$(docker run --rm -v "$ATTACHMENTS_VOL":/data alpine sh -c "find /data -type f | wc -l" 2>/dev/null | tr -d ' ' || echo "?")
    echo "  Attachment files in volume: ${FILE_COUNT}"
fi

echo ""
echo "Verification PASSED."
