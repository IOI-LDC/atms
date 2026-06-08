# Task 15 Design: Queue, Scheduler, and Failure Hardening

Date: 2026-06-09

## Overview

Harden the existing queue jobs and scheduled tasks with bounded retries, exponential backoff, explicit overlap keys, `onOneServer()` scheduling, and consistent job configuration. Add test coverage for schedule verification, job behavior, overlap prevention, and idempotency.

## 1. OverlapKeys Constants

Create `app/Support/Jobs/OverlapKeys.php`:

```
ERP_ASSET_SYNC = 'erp-asset-sync'
ERP_PART_SYNC  = 'erp-part-sync'
PM_EVALUATION  = 'pm-evaluation'
```

Used by `routes/console.php` (`withoutOverlapping($key)`) and documented for reference.

## 2. Job Configuration Changes

| Job | $tries | $backoff | $timeout | Uniqueness |
|-----|--------|----------|----------|------------|
| `SyncErpAssetsJob` | 3 | [60, 300, 900] | 3600 | `ShouldBeUnique`, `$uniqueFor = 3600` (unchanged) |
| `SyncErpPartsJob` | 3 | [60, 300, 900] | 3600 | `ShouldBeUnique`, `$uniqueFor = 3600` (unchanged) |
| `EvaluatePmRulesJob` | 3 | [60, 300, 900] | 300 | `ShouldBeUnique` added (class-name uniqueness, no `uniqueId()` override needed — no constructor args) |
| `UserActivationNotification` | 3 | [30, 120, 300] | — | — |
| `PasswordResetNotification` | 3 | [30, 120, 300] | — | — |

### EvaluatePmRulesJob refactor

Migrate from old-style traits (`Dispatchable, InteractsWithQueue, Queueable, SerializesModels`) to the newer `Illuminate\Foundation\Queue\Queueable` trait, matching the pattern used by ERP sync jobs. Add `$timeout = 300`, `$tries = 3`, `$backoff = [60, 300, 900]`, and `ShouldBeUnique` interface.

### Notifications

Keep `Illuminate\Bus\Queueable` import (do NOT change to `Illuminate\Foundation\Queue\Queueable`). Only add `$tries` and `$backoff` properties.

## 3. Schedule Hardening

```php
// routes/console.php
Schedule::job(new SyncErpAssetsJob)
    ->weekly()->mondays()->at('02:00')
    ->timezone('Africa/Tripoli')
    ->withoutOverlapping(OverlapKeys::ERP_ASSET_SYNC)
    ->onOneServer();

Schedule::job(new SyncErpPartsJob)
    ->weekly()->mondays()->at('03:00')
    ->timezone('Africa/Tripoli')
    ->withoutOverlapping(OverlapKeys::ERP_PART_SYNC)
    ->onOneServer();

Schedule::job(new EvaluatePmRulesJob)
    ->daily()->at('06:00')
    ->timezone('Africa/Tripoli')
    ->withoutOverlapping(OverlapKeys::PM_EVALUATION)
    ->onOneServer();
```

Key changes:
- Explicit day/time: Mondays at 02:00/03:00 for sync, daily at 06:00 for PM
- `onOneServer()` prevents duplicate scheduler runs in multi-instance deployments
- Overlap keys from `OverlapKeys` constants

## 4. Failure Handling

**Transient failures** (network timeouts, temporary lock conflicts): Retried automatically via `$tries=3` + `$backoff`.

**Permanent failures** (bad data, code errors): After 3 tries, jobs land in `failed_jobs` table. Ops can inspect and manually retry via `php artisan queue:retry {id}`.

**ERP row-level isolation:** Already implemented in `SyncAssets`/`SyncParts` actions — individual row errors are recorded in `erp_sync_errors` without aborting the whole job. No changes needed.

## 5. Manual Dispatch and Idempotency

- **ERP sync:** Already gated by `Gate::authorize('manage', ErpSyncJob::class)` (Admin/Manager only). Jobs are idempotent (upsert by ERP ID).
- **PM evaluation:** `EvaluatePmRule` checks `hasActiveChain()` before creating MRs. Re-running is safe.
- **Manual PM "evaluate all":** Currently calls `EvaluatePmRule` action directly in a loop (bypassing job uniqueness). This is acceptable for MVP. Document that future improvement should dispatch `EvaluatePmRulesJob` instead for stricter consistency.

## 6. Test Coverage

| Test File | What it verifies |
|-----------|-----------------|
| `tests/Feature/Jobs/ScheduleTest.php` | All 3 jobs scheduled at correct frequency/day/time, with overlap prevention and onOneServer |
| `tests/Feature/Jobs/SyncErpAssetsJobTest.php` | Job dispatches, respects tries/backoff config, row-level error isolation |
| `tests/Feature/Jobs/SyncErpPartsJobTest.php` | Same as above for parts |
| `tests/Feature/Jobs/EvaluatePmRulesJobTest.php` | Job dispatches, skips inactive rules, respects tries/backoff/timeout |
| `tests/Feature/Jobs/OverlapPreventionTest.php` | ShouldBeUnique prevents duplicate dispatch for all 3 job types |

## 7. File List

### New

```
app/Support/Jobs/OverlapKeys.php
tests/Feature/Jobs/ScheduleTest.php
tests/Feature/Jobs/SyncErpAssetsJobTest.php
tests/Feature/Jobs/SyncErpPartsJobTest.php
tests/Feature/Jobs/EvaluatePmRulesJobTest.php
tests/Feature/Jobs/OverlapPreventionTest.php
```

### Modified

```
app/Jobs/SyncErpAssetsJob.php
app/Jobs/SyncErpPartsJob.php
app/Jobs/EvaluatePmRulesJob.php
app/Notifications/UserActivationNotification.php
app/Notifications/PasswordResetNotification.php
routes/console.php
```
