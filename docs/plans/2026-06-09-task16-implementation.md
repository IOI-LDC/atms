# Task 16: Backup And Restore Operations — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Production-ready backup and restore shell scripts for PostgreSQL data and uploaded attachments, with SHA256 manifests, daily/weekly pruning, and an integration test.

**Architecture:** POSIX shell scripts run from project root (where `compose.yaml` exists). Database dumps use `pg_dump -Fc` via `docker compose exec -T`. Attachment archives use `docker run --rm` with Alpine to tar the named volume directly. Backups land in `$BACKUP_DIR/{db,attachments}/{daily,weekly}`. All scripts set `umask 077`.

**Tech Stack:** POSIX shell, `pg_dump`/`pg_restore`, GNU tar, `sha256sum`, Docker Compose, Alpine image for volume operations.

---

### Task 1: Create backup-postgres.sh

**Files:**
- Create: `scripts/backup-postgres.sh`

**Step 1: Write the script**

Create `scripts/backup-postgres.sh`:

```sh
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
```

**Step 2: Make executable**

Run: `chmod +x scripts/backup-postgres.sh`

**Step 3: Commit**

```bash
git add scripts/backup-postgres.sh
git commit -m "ops: add PostgreSQL backup script"
```

---

### Task 2: Create backup-attachments.sh

**Files:**
- Create: `scripts/backup-attachments.sh`

**Step 1: Write the script**

Create `scripts/backup-attachments.sh`:

```sh
#!/usr/bin/env sh
set -eu

umask 077

: "${BACKUP_DIR:?BACKUP_DIR is required}"

DIR="$BACKUP_DIR/attachments/daily"
TIMESTAMP=$(date -u +%Y-%m-%dT%H-%M-%S)
FILENAME="atms-attachments-${TIMESTAMP}.tar.gz"
FILEPATH="$DIR/$FILENAME"

mkdir -p "$DIR"

echo "Backing up attachments volume..."

docker run --rm \
    -v atms_attachments:/data:ro \
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
```

**Step 2: Make executable**

Run: `chmod +x scripts/backup-attachments.sh`

**Step 3: Commit**

```bash
git add scripts/backup-attachments.sh
git commit -m "ops: add attachments backup script"
```

---

### Task 3: Create restore-postgres.sh

**Files:**
- Create: `scripts/restore-postgres.sh`

**Step 1: Write the script**

Create `scripts/restore-postgres.sh`:

```sh
#!/usr/bin/env sh
set -eu

umask 077

if [ $# -lt 1 ]; then
    echo "Usage: $0 <dump-file>" >&2
    exit 1
fi

DUMP_FILE="$1"
DB_NAME="${DB_DATABASE:-atms}"
DB_USER="${DB_USERNAME:-atms}"

if [ ! -f "$DUMP_FILE" ]; then
    echo "Error: file not found: $DUMP_FILE" >&2
    exit 1
fi

case "$DUMP_FILE" in
    *.dump) ;;
    *)
        echo "Error: file must have .dump extension: $DUMP_FILE" >&2
        exit 1
        ;;
esac

echo "Restoring PostgreSQL database '$DB_NAME' from: $DUMP_FILE"
echo ""
echo "Stopping queue and scheduler to prevent writes..."
docker compose stop queue scheduler 2>/dev/null || true

echo "Dropping existing database..."
docker compose exec -T postgres dropdb -U "$DB_USER" --if-exists "$DB_NAME"

echo "Creating fresh database..."
docker compose exec -T postgres createdb -U "$DB_USER" "$DB_NAME"

echo "Restoring from dump..."
docker compose exec -T postgres pg_restore -U "$DB_USER" -d "$DB_NAME" < "$DUMP_FILE"

echo ""
echo "Row counts in restored database:"
docker compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" -c "
SELECT 'users' AS table, COUNT(*) FROM users
UNION ALL SELECT 'assets', COUNT(*) FROM assets
UNION ALL SELECT 'work_orders', COUNT(*) FROM work_orders
UNION ALL SELECT 'maintenance_requests', COUNT(*) FROM maintenance_requests
UNION ALL SELECT 'pm_rules', COUNT(*) FROM pm_rules
ORDER BY 1;
"

echo ""
echo "Restore complete. Restart queue and scheduler when ready:"
echo "  docker compose start queue scheduler"
```

**Step 2: Make executable**

Run: `chmod +x scripts/restore-postgres.sh`

**Step 3: Commit**

```bash
git add scripts/restore-postgres.sh
git commit -m "ops: add PostgreSQL restore script"
```

---

### Task 4: Create restore-attachments.sh

**Files:**
- Create: `scripts/restore-attachments.sh`

**Step 1: Write the script**

Create `scripts/restore-attachments.sh`:

```sh
#!/usr/bin/env sh
set -eu

umask 077

if [ $# -lt 1 ]; then
    echo "Usage: $0 <archive-file> [--yes]" >&2
    exit 1
fi

ARCHIVE_FILE="$1"
SKIP_CONFIRM="${2:-}"

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
    -v atms_attachments:/data \
    alpine sh -c "rm -rf /data/* /data/.* 2>/dev/null || true"

echo "Extracting archive..."
ARCHIVE_DIR=$(cd "$(dirname "$ARCHIVE_FILE")" && pwd)
ARCHIVE_BASE=$(basename "$ARCHIVE_FILE")

docker run --rm \
    -v atms_attachments:/data \
    -v "$ARCHIVE_DIR":/out \
    alpine tar -xzf "/out/$ARCHIVE_BASE" -C /data

FILE_COUNT=$(docker run --rm -v atms_attachments:/data alpine sh -c "find /data -type f | wc -l" | tr -d ' ')

echo ""
echo "Attachments restore complete."
echo "  Files restored: ${FILE_COUNT}"
```

**Step 2: Make executable**

Run: `chmod +x scripts/restore-attachments.sh`

**Step 3: Commit**

```bash
git add scripts/restore-attachments.sh
git commit -m "ops: add attachments restore script"
```

---

### Task 5: Create prune-backups.sh and copy-to-weekly.sh

**Files:**
- Create: `scripts/prune-backups.sh`
- Create: `scripts/copy-to-weekly.sh`

**Step 1: Write prune-backups.sh**

Create `scripts/prune-backups.sh`:

```sh
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
        COUNT=$(find "$DAILY_DIR" -type f -mtime +$DAILY_AGE -print | tee /dev/stderr | wc -l | tr -d ' ')
        find "$DAILY_DIR" -type f -mtime +$DAILY_AGE -delete
        PRUNED=$((PRUNED + COUNT))
    fi

    if [ -d "$WEEKLY_DIR" ]; then
        COUNT=$(find "$WEEKLY_DIR" -type f -mtime +$WEEKLY_AGE -print | tee /dev/stderr | wc -l | tr -d ' ')
        find "$WEEKLY_DIR" -type f -mtime +$WEEKLY_AGE -delete
        PRUNED=$((PRUNED + COUNT))
    fi
done

echo "Pruning complete. Removed ${PRUNED} file(s)."
```

**Step 2: Write copy-to-weekly.sh**

Create `scripts/copy-to-weekly.sh`:

```sh
#!/usr/bin/env sh
set -eu

: "${BACKUP_DIR:?BACKUP_DIR is required}"

echo "Copying latest daily backups to weekly..."

for SUBDIR in db attachments; do
    DAILY_DIR="$BACKUP_DIR/$SUBDIR/daily"
    WEEKLY_DIR="$BACKUP_DIR/$SUBDIR/weekly"

    [ -d "$DAILY_DIR" ] || continue

    mkdir -p "$WEEKLY_DIR"

    LATEST=$(ls -t "$DAILY_DIR"/*.dump "$DAILY_DIR"/*.tar.gz 2>/dev/null | head -1 || true)

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
```

**Step 3: Make executable**

Run: `chmod +x scripts/prune-backups.sh scripts/copy-to-weekly.sh`

**Step 4: Commit**

```bash
git add scripts/prune-backups.sh scripts/copy-to-weekly.sh
git commit -m "ops: add backup pruning and weekly copy scripts"
```

---

### Task 6: Create verify-restore.sh

**Files:**
- Create: `scripts/verify-restore.sh`

**Step 1: Write the script**

Create `scripts/verify-restore.sh`:

```sh
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
    FILE_COUNT=$(docker run --rm -v atms_attachments:/data alpine sh -c "find /data -type f | wc -l" 2>/dev/null | tr -d ' ' || echo "?")
    echo "  Attachment files in volume: ${FILE_COUNT}"
fi

echo ""
echo "Verification PASSED."
```

**Step 2: Make executable**

Run: `chmod +x scripts/verify-restore.sh`

**Step 3: Commit**

```bash
git add scripts/verify-restore.sh
git commit -m "ops: add backup verification script"
```

---

### Task 7: Create test-backup-restore.sh

**Files:**
- Create: `scripts/test-backup-restore.sh`

**Step 1: Write the script**

Create `scripts/test-backup-restore.sh`:

```sh
#!/usr/bin/env sh
set -eu

echo "=== ATMS Backup/Restore Integration Test ==="
echo ""
echo "Scope: manual/local ops verification, not normal CI."
echo ""

DB_USER="${DB_USERNAME:-atms}"
DB_NAME="${DB_DATABASE:-atms}"
TEST_DB="atms_test_restore_$$"
TEST_VOL="atms_test_attachments_$$"
BACKUP_DIR=$(mktemp -d)
FAILED=0

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
if [ ! -f "${DUMP_FILE}.manifest" ]; then
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
if [ ! -f "${ATT_FILE}.manifest" ]; then
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

PROD_FILE_COUNT=$(docker run --rm -v atms_attachments:/data alpine sh -c "find /data -type f | wc -l" | tr -d '[:space:]')
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
```

**Step 2: Make executable**

Run: `chmod +x scripts/test-backup-restore.sh`

**Step 3: Commit**

```bash
git add scripts/test-backup-restore.sh
git commit -m "ops: add backup/restore integration test script"
```

---

### Task 8: Create BACKUP_AND_RESTORE.md

**Files:**
- Create: `docs/operations/BACKUP_AND_RESTORE.md`

**Step 1: Write the operations guide**

Create `docs/operations/BACKUP_AND_RESTORE.md`:

```markdown
# Backup and Restore — Operations Guide

## Prerequisites

- Docker Compose stack running
- `BACKUP_DIR` environment variable set (e.g. `/opt/atms/backups`)
- Scripts run from the project root where `compose.yaml` exists
- Alpine image will be pulled automatically for attachment operations

## Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `BACKUP_DIR` | Yes | — | Local directory for backup storage |
| `DB_DATABASE` | No | `atms` | PostgreSQL database name |
| `DB_USERNAME` | No | `atms` | PostgreSQL user |

## Directory Structure

```
$BACKUP_DIR/
├── db/
│   ├── daily/     — kept 7 days
│   └── weekly/    — kept 4 weeks
└── attachments/
    ├── daily/     — kept 7 days
    └── weekly/    — kept 4 weeks
```

## Cron Schedule

Add to the VPS crontab:

```cron
# Daily backup at 02:00
0 2 * * * cd /opt/atms && BACKUP_DIR=/opt/atms/backups sh scripts/backup-postgres.sh >> /var/log/atms-backup.log 2>&1
0 2 * * * cd /opt/atms && BACKUP_DIR=/opt/atms/backups sh scripts/backup-attachments.sh >> /var/log/atms-backup.log 2>&1

# Weekly copy (Sunday at 03:00)
0 3 * * 0 cd /opt/atms && BACKUP_DIR=/opt/atms/backups sh scripts/copy-to-weekly.sh >> /var/log/atms-backup.log 2>&1

# Prune old backups at 04:00
0 4 * * * cd /opt/atms && BACKUP_DIR=/opt/atms/backups sh scripts/prune-backups.sh >> /var/log/atms-backup.log 2>&1
```

## Restore Procedure

### 1. Stop Workers

```bash
docker compose stop queue scheduler
```

### 2. Restore Database

```bash
BACKUP_DIR=/opt/atms/backups sh scripts/restore-postgres.sh /opt/atms/backups/db/daily/atms-pg-YYYY-MM-DDTHH-MM-SS.dump
```

### 3. Restore Attachments

```bash
BACKUP_DIR=/opt/atms/backups sh scripts/restore-attachments.sh /opt/atms/backups/attachments/daily/atms-attachments-YYYY-MM-DDTHH-MM-SS.tar.gz --yes
```

### 4. Verify

```bash
sh scripts/verify-restore.sh /opt/atms/backups/db/daily/atms-pg-YYYY-MM-DDTHH-MM-SS.dump.manifest
sh scripts/verify-restore.sh /opt/atms/backups/attachments/daily/atms-attachments-YYYY-MM-DDTHH-MM-SS.tar.gz.manifest
```

### 5. Restart Workers

```bash
docker compose start queue scheduler
```

## Integration Test

Run the integration test to verify backup/restore works end-to-end:

```bash
BACKUP_DIR=$(mktemp -d) sh scripts/test-backup-restore.sh
```

This creates backups, restores into a disposable test DB and volume, compares
row counts and checksums, then cleans up.

## What Is NOT Backed Up

These scripts do **not** back up:

- `.env` files and secrets (API keys, passwords, client secrets)
- Docker Compose configuration files
- Application source code

These must be backed up through a separate secure operational process.
```

**Step 2: Commit**

```bash
git add docs/operations/BACKUP_AND_RESTORE.md
git commit -m "docs: add backup and restore operations guide"
```

---

### Task 9: Run integration test

**Step 1: Verify all scripts are executable**

Run: `ls -la scripts/`

**Step 2: Run the integration test**

Requires Docker Compose running with seeded data. The stack must be up.

Run: `docker compose up -d && docker compose run --rm api php artisan migrate:fresh --seed`
Then: `BACKUP_DIR=$(mktemp -d) sh scripts/test-backup-restore.sh`

Expected: `ALL CHECKS PASSED`

**Step 3: Final commit if cleanup needed**

```bash
git add scripts docs
git commit -m "style: cleanup after integration test verification"
```
