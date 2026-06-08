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
