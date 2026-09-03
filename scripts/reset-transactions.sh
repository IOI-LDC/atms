#!/usr/bin/env bash
# Wipe all maintenance-request and work-order data and restart numbering at 1.
#
# For the pre-handover reset: run the tests, then run this so the team starts
# clean. Assets, parts, locations, users, PM rules and form templates are NOT
# touched.
#
# THIS IS DESTRUCTIVE AND IRREVERSIBLE, so it refuses to run until it has taken
# its own backup of both the database and the attachment files. If either
# backup fails, nothing is deleted.
#
#   ./scripts/reset-transactions.sh            # asks for confirmation
#   ./scripts/reset-transactions.sh --yes      # no prompt, still backs up
#
# Run from the project root.
set -euo pipefail

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Sourcing .env must NOT clobber a value the caller passed in. It did once, and
# the script silently wiped a different database than the operator named.
_caller_db="${DB_DATABASE:-}"
_caller_user="${DB_USERNAME:-}"

[ -f .env ] && { set -a; . ./.env; set +a; }

DB_NAME="${_caller_db:-${DB_DATABASE:-atms}}"
DB_USER="${_caller_user:-${DB_USERNAME:-atms}}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
STAMP="$(date -u +%Y-%m-%dT%H-%M-%S)"

psql() { docker compose exec -T postgres psql -U "$DB_USER" -d "$DB_NAME" "$@"; }

echo "Database: ${DB_NAME}"
echo
echo "About to delete:"
psql -c "
SELECT 'maintenance_requests' t, count(*) FROM maintenance_requests
UNION ALL SELECT 'work_orders',                count(*) FROM work_orders
UNION ALL SELECT 'work_order_parts',           count(*) FROM work_order_parts
UNION ALL SELECT 'work_order_forms',           count(*) FROM work_order_forms
UNION ALL SELECT 'work_order_form_fields',     count(*) FROM work_order_form_fields
UNION ALL SELECT 'work_order_pm_marks',        count(*) FROM work_order_pm_marks
UNION ALL SELECT 'work_order_meter_snapshots', count(*) FROM work_order_meter_snapshots
UNION ALL SELECT 'pm_occurrence_suppressions', count(*) FROM pm_occurrence_suppressions
UNION ALL SELECT 'attachments (+ their files)', count(*) FROM attachments
UNION ALL SELECT 'meter readings from a WO',   count(*) FROM asset_meter_readings
         WHERE work_order_id IS NOT NULL OR maintenance_request_id IS NOT NULL
ORDER BY 1;"

if [ "${1:-}" != "--yes" ]; then
  printf 'Type the database name (%s) to continue: ' "$DB_NAME"
  read -r reply
  [ "$reply" = "$DB_NAME" ] || { echo "Aborted. Nothing deleted."; exit 1; }
fi

# --- Backups. Nothing is deleted until both of these succeed. ---------------
mkdir -p "$BACKUP_DIR/reset"
DB_DUMP="$BACKUP_DIR/reset/before-reset-${STAMP}.dump"
FILES_TAR="$BACKUP_DIR/reset/before-reset-${STAMP}-attachments.tar.gz"

echo
echo "Backing up the database..."
docker compose exec -T postgres pg_dump -Fc -U "$DB_USER" -d "$DB_NAME" > "$DB_DUMP"
[ -s "$DB_DUMP" ] || { echo "ERROR: database dump is empty. Nothing deleted." >&2; exit 1; }

# The attachment files are the part a database restore cannot bring back.
echo "Backing up the attachment files..."
docker compose exec -T api tar -cz -C /var/www/html/storage/app attachments > "$FILES_TAR"
[ -s "$FILES_TAR" ] || { echo "ERROR: attachment archive is empty. Nothing deleted." >&2; exit 1; }

echo "  $DB_DUMP  ($(wc -c < "$DB_DUMP" | tr -d ' ') bytes)"
echo "  $FILES_TAR  ($(wc -c < "$FILES_TAR" | tr -d ' ') bytes)"

# --- Delete. One transaction: it all goes or none of it does. ---------------
echo
echo "Deleting..."
psql -1 -v ON_ERROR_STOP=1 -c "
-- Readings taken during a work order are test data; standalone readings are
-- asset truth and stay.
DELETE FROM asset_meter_readings
 WHERE work_order_id IS NOT NULL OR maintenance_request_id IS NOT NULL;

-- Every attachment belongs to an MR or a WO (attachable_type is only ever
-- 'maintenance_request' or 'work_order'), so the table empties completely.
TRUNCATE TABLE
  work_order_form_fields, work_order_forms, work_order_parts,
  work_order_pm_marks, work_order_meter_snapshots,
  pm_occurrence_suppressions, attachments
RESTART IDENTITY;

-- DELETE, not TRUNCATE: asset_meter_readings has a foreign key to work_orders,
-- and Postgres refuses to truncate a referenced table even once the
-- referencing rows are gone. The sequences are restarted by hand below.
DELETE FROM work_orders;
DELETE FROM maintenance_requests;
ALTER SEQUENCE work_orders_id_seq RESTART WITH 1;
ALTER SEQUENCE maintenance_requests_id_seq RESTART WITH 1;

-- The audit trail of the work just deleted.
DELETE FROM audit_logs WHERE subject_type IN (
  'maintenance_request', 'work_order', 'attachment',
  'App\\\\Models\\\\WorkOrderForm', 'App\\\\Models\\\\WorkOrderFormField',
  'App\\\\Models\\\\WorkOrderPart', 'App\\\\Models\\\\Attachment',
  'App\\\\Models\\\\PmOccurrenceSuppression', 'App\\\\Models\\\\AssetMeterReading');

-- Next request is MR-001, next work order WO-001.
UPDATE business_number_sequences SET current_value = 0, updated_at = now();
"

# Only after the transaction committed. Files, not rows.
docker compose exec -T api sh -c 'rm -rf /var/www/html/storage/app/attachments/* 2>/dev/null || true'

echo
echo "Done. Remaining:"
psql -c "
SELECT 'maintenance_requests' t, count(*) FROM maintenance_requests
UNION ALL SELECT 'work_orders', count(*) FROM work_orders
UNION ALL SELECT 'attachments', count(*) FROM attachments
UNION ALL SELECT 'asset_meter_readings', count(*) FROM asset_meter_readings
ORDER BY 1;"
psql -c "SELECT type, current_value FROM business_number_sequences ORDER BY type;"

cat <<NOTE

To undo this:
  docker compose exec -T postgres pg_restore -U ${DB_USER} -d ${DB_NAME} --clean --if-exists < ${DB_DUMP}
  docker compose exec -T api tar -xz -C /var/www/html/storage/app < ${FILES_TAR}

NOT reset by this script -- assets keep whatever the test work orders left on
them. Check these if the tests changed them:
  * assets.operational_status / maintenance_status  (set when a WO closes)
  * asset_pm_assignments.last_triggered_date / _reading  (PM baselines)
  * parts.available_quantity  (decremented by WO consumption)
NOTE
