# Jobs and Scheduler

## Scheduled Jobs

### ERP Asset Sync

Purpose: import/update fixed assets from ERP.

Default schedule: once per week in the `Africa/Tripoli` company timezone.

### ERP Parts Sync

Purpose: import/update parts reference data from ERP.

Default schedule: once per week in the `Africa/Tripoli` company timezone.

### PM Rule Evaluation

Purpose: check active PM rules and generate Preventive Maintenance Requests when due.

Default schedule: once per day in the `Africa/Tripoli` company timezone.

Daily evaluation applies to date, reading, and `date_or_reading` rules. This
matches the expected maximum daily frequency of confirmed meter updates.

### Housekeeping

Purpose: clean temporary uploads, old failed jobs if needed, and other maintenance tasks.

Housekeeping must not purge soft-deleted attachment metadata or physical files.
Those are retained indefinitely in MVP.

## Queue Jobs

Use Laravel Queue for long-running or repeatable tasks.

Recommended jobs:

> ✅ Phase 1 removed  — assets are ATMS-managed only.

- SyncErpPartsJob
- EvaluatePmRulesJob
- GeneratePreventiveMaintenanceRequestJob
- ProcessAttachmentJob, optional

## Queue Driver

The MVP must use Laravel's database queue driver backed by PostgreSQL.

Redis is an optional future upgrade if queue volume or operational requirements
justify it. Redis must not be included in the default first deployment.

## Scheduling Rules

- Administrator may configure the scheduled run time.
- Administrator and Maintenance Manager may trigger manual ERP sync runs.
- Administrator and Maintenance Manager may trigger manual PM evaluation.
- Scheduled and manual jobs must use overlap prevention.
- Concurrent ERP sync or PM evaluation runs must not create duplicate work.
