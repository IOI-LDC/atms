#!/usr/bin/env sh
set -eu

echo "Checking Docker Compose services..."

REQUIRED="nginx api postgres queue scheduler"
PROFILE_SERVICE="mock-erp"
MISSING=0

for svc in $REQUIRED; do
  if docker compose config --services 2>/dev/null | grep -qx "$svc"; then
    echo "  OK: $svc"
  else
    echo "  MISSING: $svc"
    MISSING=1
  fi
done

if docker compose --profile mock-erp config --services 2>/dev/null | grep -qx "$PROFILE_SERVICE"; then
  echo "  OK: $PROFILE_SERVICE (profile)"
else
  echo "  MISSING: $PROFILE_SERVICE (profile)"
  MISSING=1
fi

if [ "$MISSING" -eq 0 ]; then
  echo "All services found."
  exit 0
else
  echo "Some services are missing."
  exit 1
fi
