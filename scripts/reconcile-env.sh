#!/usr/bin/env bash
# ===========================================================================
# Reconcile a deployed .env against .env.production.example.
#
# Rebuilds .env in the template's shape and order: every value already set in
# the current .env is carried over untouched, obsolete keys the template no
# longer lists are dropped, and keys the template has added appear with their
# default. Server-specific secrets (APP_KEY, DB_PASSWORD, hosts) are preserved,
# so this is safe to run on a live deployment.
#
# Prints what it removed and what still needs a value. Always writes a backup.
#
#   ./scripts/reconcile-env.sh          # apply
#   ./scripts/reconcile-env.sh --dry-run  # report only, change nothing
# ===========================================================================
set -euo pipefail

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

DRY_RUN=0
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=1

[[ -f .env ]] || { echo "ERROR: .env not found in $(pwd)" >&2; exit 1; }
[[ -f .env.production.example ]] || { echo "ERROR: .env.production.example not found." >&2; exit 1; }
command -v python3 >/dev/null 2>&1 || { echo "ERROR: python3 is required." >&2; exit 1; }

BACKUP=".env.bak.$(date +%Y%m%d-%H%M%S)"
if [[ $DRY_RUN -eq 0 ]]; then
  cp .env "$BACKUP"
  echo "Backup written to $BACKUP"
fi

DRY_RUN=$DRY_RUN python3 - <<'PY'
import os, re

KEY = re.compile(r'^([A-Z_][A-Z0-9_]*)=(.*)$')
dry = os.environ.get('DRY_RUN') == '1'

current = {}
for line in open('.env'):
    m = KEY.match(line.rstrip('\n'))
    if m:
        current[m.group(1)] = m.group(2)

lines, template_keys = [], []
for line in open('.env.production.example'):
    line = line.rstrip('\n')
    m = KEY.match(line)
    if m:
        key, default = m.group(1), m.group(2)
        template_keys.append(key)
        lines.append(f"{key}={current.get(key, default)}")
    else:
        lines.append(line)

removed = sorted(set(current) - set(template_keys))
added = [k for k in template_keys if k not in current]
missing = [k for k in template_keys if not (current.get(k) or dict(
    (l.split('=', 1)[0], l.split('=', 1)[1]) for l in lines if KEY.match(l)
).get(k))]

if not dry:
    with open('.env', 'w') as fh:
        fh.write('\n'.join(lines).rstrip('\n') + '\n')

print()
print("REMOVED (obsolete, no longer in the template):")
print("  " + (", ".join(removed) if removed else "none"))
print()
print("ADDED (new in the template, took its default):")
print("  " + (", ".join(added) if added else "none"))
print()
print("STILL NEEDS A VALUE (currently empty):")
print("  " + (", ".join(missing) if missing else "none"))
print()
print("DRY RUN — .env was not modified." if dry else "Applied to .env.")
PY
