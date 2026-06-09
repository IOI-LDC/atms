#!/usr/bin/env sh
set -eu

echo "Running security and concurrency tests..."
docker compose run --rm api php artisan test tests/Feature/Security tests/Feature/Concurrency
echo "Done."
