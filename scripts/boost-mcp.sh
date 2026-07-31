#!/usr/bin/env bash
# Boost MCP bridge — runs php artisan boost:mcp inside the Docker container.
# Used by Kilo's MCP config to give AI agents access to Boost tools.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"
exec docker compose exec -T api php artisan boost:mcp
