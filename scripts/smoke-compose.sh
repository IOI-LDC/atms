#!/usr/bin/env sh
set -eu

echo "Checking Docker Compose services..."

REQUIRED="nginx api postgres queue scheduler"
MISSING=0

for svc in $REQUIRED; do
  if docker compose config --services 2>/dev/null | grep -qx "$svc"; then
    echo "  OK: $svc"
  else
    echo "  MISSING: $svc"
    MISSING=1
  fi
done

if [ "$MISSING" -eq 0 ]; then
  echo "All services found."
  exit 0
else
  echo "Some services are missing."
  exit 1
fi
