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
