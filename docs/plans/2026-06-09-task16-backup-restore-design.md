# Task 16: Backup And Restore Operations — Design

**Goal:** Production-ready backup and restore scripts for PostgreSQL data and uploaded attachments, with manifest checksums, age-based pruning, and an integration test.

**Architecture:** POSIX shell scripts that run from the project root (where `compose.yaml` exists). Database dumps use `pg_dump -Fc` (custom format) via `docker compose exec -T`. Attachment archives use `docker run` with Alpine to tar the named volume directly. Backups land in a local directory organized by daily/weekly folders.

**Tech Stack:** POSIX shell, `pg_dump`/`pg_restore`, GNU tar, sha256sum, Docker Compose, Alpine image for volume operations.

---

## Scripts

### `scripts/backup-postgres.sh`

- **Requires:** `BACKUP_DIR` env var, Docker Compose running, `postgres` service healthy
- **Runs from:** project root (where `compose.yaml` exists)
- **Steps:**
  1. `umask 077`
  2. Ensure `$BACKUP_DIR/db/daily` exists
  3. Generate filename: `atms-pg-$(date -u +%Y-%m-%dT%H-%M-%S).dump`
  4. `docker compose exec -T postgres pg_dump -Fc -U $DB_USERNAME -d $DB_DATABASE > "$filepath"`
  5. Compute SHA256 of the compressed `.dump` file
  6. Write manifest: `$filepath.manifest` containing filename, size, sha256, timestamp
  7. Print summary to stdout

### `scripts/backup-attachments.sh`

- **Requires:** `BACKUP_DIR` env var, Docker running
- **Runs from:** project root
- **Steps:**
  1. `umask 077`
  2. Ensure `$BACKUP_DIR/attachments/daily` exists
  3. Generate filename: `atms-attachments-$(date -u +%Y-%m-%dT%H-%M-%S).tar.gz`
  4. `docker run --rm -v atms_attachments:/data:ro -v "$BACKUP_DIR/attachments/daily":/out alpine tar -czf "/out/$filename" -C /data .`
  5. Compute SHA256 of the `.tar.gz` file
  6. Write manifest alongside archive
  7. Print summary to stdout

### `scripts/restore-postgres.sh`

- **Args:** `$1` = path to `.dump` file (required)
- **Requires:** `BACKUP_DIR` env var, Docker Compose running
- **Steps:**
  1. Verify file exists and has `.dump` extension
  2. Stop `queue` and `scheduler` containers to prevent writes
  3. `docker compose exec -T postgres dropdb -U $DB_USERNAME --if-exists $DB_DATABASE`
  4. `docker compose exec -T postgres createdb -U $DB_USERNAME $DB_DATABASE`
  5. `docker compose exec -T postgres pg_restore -U $DB_USERNAME -d $DB_DATABASE < "$filepath"`
  6. Print row count summary (users, assets, work_orders, maintenance_requests)
  7. Remind operator to restart `queue` and `scheduler`

### `scripts/restore-attachments.sh`

- **Args:** `$1` = path to `.tar.gz` file (required), optional `--yes` to skip confirmation
- **Requires:** Docker running
- **Steps:**
  1. Verify file exists and has `.tar.gz` extension
  2. Unless `--yes`, prompt: "This will replace all attachments. Continue? [y/N]"
  3. `docker run --rm -v atms_attachments:/data alpine sh -c "rm -rf /data/* /data/.* 2>/dev/null; true"`
  4. `docker run --rm -v atms_attachments:/data -v "$(dirname "$filepath")":/out alpine tar -xzf "/out/$(basename "$filepath")" -C /data`
  5. Print file count summary

### `scripts/prune-backups.sh`

- **Requires:** `BACKUP_DIR` env var
- **Steps:**
  1. In `$BACKUP_DIR/db/daily/`: delete files older than 7 days
  2. In `$BACKUP_DIR/attachments/daily/`: delete files older than 7 days
  3. In `$BACKUP_DIR/db/weekly/`: delete files older than 28 days
  4. In `$BACKUP_DIR/attachments/weekly/`: delete files older than 28 days
  5. Print deletion summary
- **Note:** Weekly folders are populated by a separate cron invocation (see schedule below)

### `scripts/verify-restore.sh`

- **Args:** `$1` = manifest file path (required)
- **Requires:** Docker Compose running
- **Steps:**
  1. Parse manifest for expected SHA256 and filename
  2. Verify the archive file exists at the manifest's path
  3. Recompute SHA256, compare to manifest value
  4. If DB manifest: query row counts from restored DB, print summary
  5. If attachment manifest: count files in volume, print summary
  6. Exit 0 on match, exit 1 on mismatch

### `scripts/test-backup-restore.sh`

- **Scope:** Integration test for manual/local verification, not normal CI
- **Requires:** Docker Compose running with seeded data
- **Steps:**
  1. Create a temp backup directory
  2. Run `backup-postgres.sh`, verify `.dump` + `.manifest` exist
  3. Run `backup-attachments.sh`, verify `.tar.gz` + `.manifest` exist
  4. Create a disposable test DB: `docker compose exec -T postgres createdb -U $DB_USERNAME atms_test_restore`
  5. Restore the dump into the test DB
  6. Compare row counts between production DB and test DB
  7. Create a disposable test volume, restore attachments into it
  8. Compare file count and checksums
  9. Clean up: drop test DB, remove test volume
  10. Print PASS/FAIL summary

---

## Directory Layout

```
$BACKUP_DIR/
├── db/
│   ├── daily/
│   │   ├── atms-pg-2026-06-09T02-00-00.dump
│   │   └── atms-pg-2026-06-09T02-00-00.dump.manifest
│   └── weekly/
│       ├── atms-pg-2026-06-08T02-00-00.dump
│       └── atms-pg-2026-06-08T02-00-00.dump.manifest
└── attachments/
    ├── daily/
    │   ├── atms-attachments-2026-06-09T02-00-00.tar.gz
    │   └── atms-attachments-2026-06-09T02-00-00.tar.gz.manifest
    └── weekly/
        ├── atms-attachments-2026-06-08T02-00-00.tar.gz
        └── atms-attachments-2026-06-08T02-00-00.tar.gz.manifest
```

## Manifest Format

```
filename=atms-pg-2026-06-09T02-00-00.dump
size=1048576
sha256=abc123def456...
timestamp=2026-06-09T02:00:00Z
type=db
```

```
filename=atms-attachments-2026-06-09T02-00-00.tar.gz
size=524288
sha256=789ghi012jkl...
timestamp=2026-06-09T02:00:00Z
type=attachments
```

## Schedule

Crontab on the VPS (operator adds manually):

```cron
# Daily backup at 02:00 (server timezone)
0 2 * * * cd /opt/atms && BACKUP_DIR=/opt/atms/backups sh scripts/backup-postgres.sh >> /var/log/atms-backup.log 2>&1
0 2 * * * cd /opt/atms && BACKUP_DIR=/opt/atms/backups sh scripts/backup-attachments.sh >> /var/log/atms-backup.log 2>&1

# Weekly backup (Sunday) — copies latest daily to weekly folder
0 3 * * 0 cd /opt/atms && BACKUP_DIR=/opt/atms/backups sh scripts/copy-to-weekly.sh >> /var/log/atms-backup.log 2>&1

# Prune at 04:00
0 4 * * * cd /opt/atms && BACKUP_DIR=/opt/atms/backups sh scripts/prune-backups.sh >> /var/log/atms-backup.log 2>&1
```

## Key Decisions

- **`pg_dump -Fc`** (custom format): produces compressed output, enables parallel restore, selective restore, and is the PostgreSQL recommended format.
- **`docker compose exec -T`** for DB operations: disables pseudo-TTY allocation, required for piping host files into containers.
- **`docker run --rm` with Alpine** for attachment operations: directly accesses named volumes without needing a project container. Alpine is ~5MB.
- **Daily/weekly folder structure**: avoids GNU/BSD `date` compatibility issues with Sunday detection. Pruning is simple `find -mtime`.
- **SHA256 of compressed archive**: checksums the actual stored file, not the uncompressed data. Manifest records this explicitly.
- **`umask 077`**: backup files contain a full database dump — they must be private by default.
- **`.env` and secrets excluded**: these scripts do not back up `.env`, API keys, or credentials. Document that these must be backed up through a separate secure operational process.

## Constraints

- All scripts run from the project root where `compose.yaml` exists
- No `COMPOSE_PROJECT` variable — uses default Docker Compose project name from directory
- Scripts are POSIX sh compatible (no bashisms)
- No external dependencies beyond Docker, `pg_dump`/`pg_restore`, `tar`, `sha256sum`
- Attachment restore is destructive — clears volume before extraction
- Restore requires manual stop of `queue` and `scheduler` containers (documented)
