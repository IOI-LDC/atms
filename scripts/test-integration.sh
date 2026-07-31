#!/usr/bin/env sh
set -eu

PASS=0
FAIL=0

check() {
    label="$1"
    shift
    if "$@" >/dev/null 2>&1; then
        printf "  PASS  %s\n" "$label"
        PASS=$((PASS + 1))
    else
        printf "  FAIL  %s\n" "$label"
        FAIL=$((FAIL + 1))
    fi
}

echo "=== ATMS Integration Tests ==="

check "postgres is healthy" docker compose exec -T postgres pg_isready -U atms
check "api health/live is reachable" test "$(curl -sf -o /dev/null -w '%{http_code}' http://localhost:80/api/health/live)" = "200"
check "nginx proxies to api" test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:80/up)" = "200"
check "queue worker is running" docker compose exec -T queue pgrep -f "queue:work"
check "scheduler is running" docker compose exec -T scheduler pgrep -f "schedule:work"

echo ""
echo "Results: $PASS passed, $FAIL failed"

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi
