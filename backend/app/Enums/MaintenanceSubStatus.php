<?php

namespace App\Enums;

/**
 * ⚠️ **Deprecated — nothing reads this.** The 2026-08-16 vocabulary design
 * retired sub-statuses: assembly state (`installed`/`ready`) is derived from
 * `parent_asset_id` (🟠 P2-001), and the withdrawal labels below — `lih`, `dbr`,
 * `disposed`, `scrapped` — were given no home, because an asset that has left
 * the fleet is simply deactivated (`is_active = false`).
 *
 * Release 4b removed the cast, the fillable entry, the API validation and the
 * resource field. The enum and the `assets.maintenance_sub_status` column are
 * both **retained on purpose** until Phase 2 Assembly is specified — the column
 * holds only NULLs, and keeping the vocabulary that explains its values costs
 * nothing while its future is undecided.
 *
 * Do not add readers. If Phase 2 needs stored assembly state it should get a
 * purpose-built column rather than reusing one that also carried withdrawal
 * dispositions.
 */
enum MaintenanceSubStatus: string
{
    case INSTALLED = 'installed';
    case READY = 'ready';
    case LIH = 'lih';
    case DBR = 'dbr';
    case DISPOSED = 'disposed';
    case SCRAPPED = 'scrapped';
    case OTHER = 'other';
}
