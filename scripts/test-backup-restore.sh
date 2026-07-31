#!/usr/bin/env sh
set -eu

echo "=== ATMS Backup/Restore Integration Test ==="
echo ""
echo "Scope: manual/local ops verification, not normal CI."
echo ""
echo "Prerequisite: database must be seeded with meaningful data."
echo "  Run: docker compose run --rm api php artisan migrate:fresh --seed"
echo "       docker compose run --rm api php artisan db:seed --class=DemoDataSeeder"
echo ""

DB_USER="${DB_USERNAME:-atms}"
DB_NAME="${DB_DATABASE:-atms}"
TEST_DB="atms_test_restore_$$"
TEST_VOL="atms_test_attachments_$$"
BACKUP_DIR=$(mktemp -d)
FAILED=0

ATTACHMENTS_VOL=$(docker volume ls --format '{{.Name}}' | grep 'attachments$' | head -1)
: "${ATTACHMENTS_VOL:?Could not find attachments Docker volume}"

cleanup() {
    echo ""
    echo "Cleaning up..."
    docker compose exec -T postgres dropdb -U "$DB_USER" --if-exists "$TEST_DB" 2>/dev/null || true
    docker volume rm "$TEST_VOL" 2>/dev/null || true
    rm -rf "$BACKUP_DIR"
    echo "Cleanup done."
}
trap cleanup EXIT

export BACKUP_DIR

echo "--- Step 1: Backup PostgreSQL ---"
sh scripts/backup-postgres.sh
DUMP_FILE=$(ls "$BACKUP_DIR/db/daily/"*.dump 2>/dev/null | head -1)
if [ -z "$DUMP_FILE" ]; then
    echo "FAIL: no dump file produced"
    FAILED=1
fi
if [ -n "$DUMP_FILE" ] && [ ! -f "${DUMP_FILE}.manifest" ]; then
    echo "FAIL: no manifest for dump"
    FAILED=1
fi
echo "  Dump: $DUMP_FILE"

echo ""
echo "--- Step 2: Backup Attachments ---"
sh scripts/backup-attachments.sh
ATT_FILE=$(ls "$BACKUP_DIR/attachments/daily/"*.tar.gz 2>/dev/null | head -1)
if [ -z "$ATT_FILE" ]; then
    echo "FAIL: no attachment archive produced"
    FAILED=1
fi
if [ -n "$ATT_FILE" ] && [ ! -f "${ATT_FILE}.manifest" ]; then
    echo "FAIL: no manifest for attachments"
    FAILED=1
fi
echo "  Archive: $ATT_FILE"

echo ""
echo "--- Step 3: Restore PostgreSQL to test database ---"
docker compose exec -T postgres createdb -U "$DB_USER" "$TEST_DB"
docker compose exec -T postgres pg_restore -U "$DB_USER" -d "$TEST_DB" < "$DUMP_FILE"

echo ""
echo "--- Step 4: Compare row counts ---"
PROD_COUNTS=$(docker compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -t -A -c "
SELECT COALESCE(SUM(c), 0) FROM (
    SELECT COUNT(*) AS c FROM users
    UNION ALL SELECT COUNT(*) FROM assets
    UNION ALL SELECT COUNT(*) FROM work_orders
    UNION ALL SELECT COUNT(*) FROM maintenance_requests
    UNION ALL SELECT COUNT(*) FROM pm_rules
) sub;
")
TEST_COUNTS=$(docker compose exec -T postgres psql -U "$DB_USER" -d "$TEST_DB" -t -A -c "
SELECT COALESCE(SUM(c), 0) FROM (
    SELECT COUNT(*) AS c FROM users
    UNION ALL SELECT COUNT(*) FROM assets
    UNION ALL SELECT COUNT(*) FROM work_orders
    UNION ALL SELECT COUNT(*) FROM maintenance_requests
    UNION ALL SELECT COUNT(*) FROM pm_rules
) sub;
")

PROD_COUNTS=$(echo "$PROD_COUNTS" | tr -d '[:space:]')
TEST_COUNTS=$(echo "$TEST_COUNTS" | tr -d '[:space:]')

echo "  Production total rows: ${PROD_COUNTS}"
echo "  Restored total rows:   ${TEST_COUNTS}"

if [ "$PROD_COUNTS" != "$TEST_COUNTS" ]; then
    echo "FAIL: row count mismatch"
    FAILED=1
else
    echo "  Row counts: OK"
fi

echo ""
echo "--- Step 5: Restore Attachments to test volume ---"
docker volume create "$TEST_VOL" >/dev/null

ARCHIVE_DIR=$(cd "$(dirname "$ATT_FILE")" && pwd)
ARCHIVE_BASE=$(basename "$ATT_FILE")

docker run --rm \
    -v "$TEST_VOL":/data \
    -v "$ARCHIVE_DIR":/out \
    alpine tar -xzf "/out/$ARCHIVE_BASE" -C /data

PROD_FILE_COUNT=$(docker run --rm -v "$ATTACHMENTS_VOL":/data alpine sh -c "find /data -type f | wc -l" | tr -d '[:space:]')
TEST_FILE_COUNT=$(docker run --rm -v "$TEST_VOL":/data alpine sh -c "find /data -type f | wc -l" | tr -d '[:space:]')

echo "  Production attachment files: ${PROD_FILE_COUNT}"
echo "  Restored attachment files:   ${TEST_FILE_COUNT}"

if [ "$PROD_FILE_COUNT" != "$TEST_FILE_COUNT" ]; then
    echo "FAIL: attachment file count mismatch"
    FAILED=1
else
    echo "  File counts: OK"
fi

echo ""
echo "--- Step 6: Verify SHA256 checksums ---"
EXPECTED_DUMP_SHA=$(grep '^sha256=' "${DUMP_FILE}.manifest" | cut -d= -f2)
ACTUAL_DUMP_SHA=$(sha256sum "$DUMP_FILE" | cut -d' ' -f1)

if [ "$ACTUAL_DUMP_SHA" != "$EXPECTED_DUMP_SHA" ]; then
    echo "FAIL: dump SHA256 mismatch"
    FAILED=1
else
    echo "  Dump SHA256: OK"
fi

EXPECTED_ATT_SHA=$(grep '^sha256=' "${ATT_FILE}.manifest" | cut -d= -f2)
ACTUAL_ATT_SHA=$(sha256sum "$ATT_FILE" | cut -d' ' -f1)

if [ "$ACTUAL_ATT_SHA" != "$EXPECTED_ATT_SHA" ]; then
    echo "FAIL: attachment archive SHA256 mismatch"
    FAILED=1
else
    echo "  Attachment SHA256: OK"
fi

echo ""
echo "=== Result ==="
if [ "$FAILED" -eq 0 ]; then
    echo "ALL CHECKS PASSED"
    exit 0
else
    echo "SOME CHECKS FAILED"
    exit 1
fi
