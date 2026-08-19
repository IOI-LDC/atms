<?php

namespace App\Support\Assets;

use App\Enums\MaintenanceStatus;
use App\Models\Asset;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single answer to "may new maintenance work start against this asset?".
 *
 * Two independent axes say no, for different reasons:
 *
 *  - `maintenance_status = withdrawn` — the asset still exists and is still
 *    tracked, but LDC has taken it out of the maintenance programme.
 *  - `is_active = false` — the asset record itself is deactivated. Since the
 *    2026-08-16 vocabulary design this is also what "scrapped" means: the
 *    `scraped` operational value is gone and `is_active` is the only
 *    out-of-ATMS control, which is why this axis must block as hard as
 *    `withdrawn` does.
 *
 * The two are kept **cause-distinct** in the message. They are set by
 * different people for different reasons, and an operator who is told only
 * "inactive asset" cannot tell which switch to flip to fix it.
 *
 * ⚠️ This is a *start* guard, never a *finish* guard. Work already under way
 * on an asset that has since been withdrawn or deactivated must still be
 * closable and cancellable — stranding an open work order helps nobody, and
 * closing it is how the asset stops being someone's problem. Do not call this
 * from complete, close or cancel.
 *
 * Deliberately an explicit guard rather than a global model scope: a scope
 * would also hide these assets from the read paths that are supposed to show
 * them (Admin lists, reports, the asset's own detail page), and it would make
 * the block invisible at the call site.
 */
class AssetWorkEligibility
{
    /**
     * Throw unless new work may start against $asset.
     *
     * @param  ?Asset  $asset  null passes deliberately. Several callers hold an
     *                         optional asset — a work order raised against no
     *                         asset, a maintenance request still unassigned —
     *                         and "no asset" is not an ineligible one. Callers
     *                         that require an asset enforce that themselves,
     *                         through validation, before reaching this guard.
     * @param  string  $verb  the action being attempted, phrased to follow
     *                        "Cannot " and precede " for …" — e.g.
     *                        "assign a work order", "update location".
     *
     * @throws DomainException
     */
    public static function guard(?Asset $asset, string $verb): void
    {
        if ($asset === null) {
            return;
        }

        if ($asset->maintenance_status === MaintenanceStatus::WITHDRAWN) {
            throw new DomainException("Cannot {$verb} for an asset withdrawn from maintenance.");
        }

        if (! $asset->is_active) {
            throw new DomainException("Cannot {$verb} for a deactivated asset.");
        }
    }

    /**
     * Lock the asset row, then guard it. **Use this, not {@see guard()}, on any
     * path that goes on to create or transition work.**
     *
     * ⚠️ Being inside a transaction is not the same as holding the row.
     *
     * Every caller here used to read the asset with a plain `SELECT` — often a
     * lazy relationship like `$lockedWorkOrder->asset`, which reads correctly as
     * "the locked one" and is nothing of the sort: the lock is on the work
     * order. Under READ COMMITTED, a concurrent withdrawal or deactivation can
     * commit between that read and this transaction's own write, and nothing
     * serialises the two. Booking creation showed it most plainly —
     * `Asset::booted` released the asset's bookings, and the request already
     * past the guard then inserted a fresh active one.
     *
     * Taking the lock here makes the check and the work that depends on it one
     * atomic decision, and returning the locked model means the caller has no
     * reason to reach for the unlocked one again.
     *
     * **Lock order.** Each caller keeps whatever primary lock it already takes —
     * the work order, the PM assignment, the maintenance request — and locks the
     * asset after it. There is deliberately no global table order: imposing one
     * would mean reordering established, working code for no gain, and the
     * asset is always the *last* row these actions need.
     *
     * @param  ?Asset  $asset  null passes, exactly as in {@see guard()}
     * @return ?Asset the locked row, or null when $asset was null
     *
     * @throws DomainException
     */
    public static function lockAndGuard(?Asset $asset, string $verb): ?Asset
    {
        if ($asset === null) {
            return null;
        }

        $locked = Asset::where('id', $asset->id)->lockForUpdate()->first();

        // Deleted between the read and the lock. Nothing to do work against.
        if ($locked === null) {
            throw new DomainException("Cannot {$verb} for an asset that no longer exists.");
        }

        self::guard($locked, $verb);

        return $locked;
    }

    /**
     * The query-side twin of {@see guard()}, for the paths that select a
     * population instead of checking one row — the PM scheduler and the
     * evaluate-all batch.
     *
     * Columns are table-qualified: these constraints are applied inside
     * `whereHas('asset', …)` subqueries that sit beside joins on
     * `asset_pm_assignments`, where a bare `is_active` is ambiguous.
     *
     * @param  Builder<Asset>  $query
     * @return Builder<Asset>
     */
    public static function scope(Builder $query): Builder
    {
        return $query
            ->where('assets.maintenance_status', MaintenanceStatus::ENROLLED)
            ->where('assets.is_active', true);
    }
}
