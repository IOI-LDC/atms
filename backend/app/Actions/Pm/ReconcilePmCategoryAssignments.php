<?php

namespace App\Actions\Pm;

use App\Enums\MaintenanceStatus;
use App\Enums\PmAssignmentOrigin;
use App\Models\Asset;
use App\Models\AssetPmAssignment;
use App\Models\PmRule;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Brings `asset_pm_assignments` back in step with the categories a PM rule
 * covers. This is the real work of D-012 — the pivot is only a statement of
 * intent until something expands it.
 *
 * ## What it is allowed to touch
 *
 * Only CATEGORY-origin rows. A manual per-asset assignment is somebody's
 * decision about one asset and is never withdrawn by a category change.
 *
 * ## Precedence: it may undo itself, never a person
 *
 * Withdrawals it makes leave `deactivated_by` null; a person's withdrawal fills
 * it in. Reconciliation restores only the former. Without that distinction, an
 * operator switching one asset's PM off would find it silently back on after
 * the next asset edit anywhere in the category.
 *
 * ## Cost
 *
 * Both directions are chunked. A category can hold hundreds of assets and a
 * rule can cover several categories, so nothing here may load the covered set
 * into memory at once — the same mistake D-013 removed from PM evaluation.
 *
 * ## Audit
 *
 * One entry per reconciliation run, carrying counts — not N entries for an
 * N-asset category, which would bury every other event in the log.
 */
class ReconcilePmCategoryAssignments
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private CreateAssetPmAssignment $create,
        private DeactivateAssetPmAssignment $deactivate,
        private ReactivateAssetPmAssignment $reactivate,
    ) {}

    /**
     * Reconcile one rule against every asset in the categories it covers.
     *
     * @return array{created: int, restored: int, withdrawn: int, skipped: int}
     */
    public function forRule(PmRule $rule): array
    {
        $categoryIds = $rule->maintenanceCategories()->pluck('maintenance_categories.id');
        $outcome = ['created' => 0, 'restored' => 0, 'withdrawn' => 0, 'skipped' => 0];

        if ($categoryIds->isNotEmpty() && $rule->is_active) {
            $this->eligibleAssets($categoryIds)
                ->chunkById(self::CHUNK_SIZE, function (Collection $assets) use ($rule, &$outcome) {
                    $existing = AssetPmAssignment::query()
                        ->where('pm_rule_id', $rule->id)
                        ->whereIn('asset_id', $assets->pluck('id'))
                        ->get()
                        ->keyBy('asset_id');

                    foreach ($assets as $asset) {
                        $this->ensureCovered($rule, $asset, $existing->get($asset->id), $outcome);
                    }
                });
        }

        // Withdraw rows for assets this rule's categories no longer reach —
        // whether because the link was removed or the asset moved category.
        AssetPmAssignment::query()
            ->where('pm_rule_id', $rule->id)
            ->where('origin', PmAssignmentOrigin::CATEGORY)
            ->where('is_active', true)
            ->with('asset')
            ->chunkById(self::CHUNK_SIZE, function (Collection $assignments) use ($categoryIds, $rule, &$outcome) {
                foreach ($assignments as $assignment) {
                    $stillCovered = $rule->is_active
                        && $assignment->asset !== null
                        && $categoryIds->contains($assignment->asset->maintenance_category_id)
                        && $this->isEligible($assignment->asset);

                    if (! $stillCovered) {
                        $this->withdraw($assignment, $outcome);
                    }
                }
            });

        app(AuditLogger::class)->log('pm_rule.categories_reconciled', $rule, [], $outcome, [
            'maintenance_category_ids' => $categoryIds->all(),
        ]);

        return $outcome;
    }

    /**
     * Reconcile one asset against every rule covering its category — the path
     * taken when an asset is created or moves category.
     *
     * @return array{created: int, restored: int, withdrawn: int, skipped: int}
     */
    public function forAsset(Asset $asset): array
    {
        $outcome = ['created' => 0, 'restored' => 0, 'withdrawn' => 0, 'skipped' => 0];

        $coveringRuleIds = PmRule::query()
            ->where('is_active', true)
            ->whereHas(
                'maintenanceCategories',
                fn (Builder $q) => $q->where('maintenance_categories.id', $asset->maintenance_category_id),
            )
            ->pluck('id');

        $existing = AssetPmAssignment::query()
            ->where('asset_id', $asset->id)
            ->get()
            ->keyBy('pm_rule_id');

        if ($this->isEligible($asset)) {
            foreach (PmRule::query()->whereIn('id', $coveringRuleIds)->get() as $rule) {
                $this->ensureCovered($rule, $asset, $existing->get($rule->id), $outcome);
            }
        }

        foreach ($existing as $assignment) {
            $stale = $assignment->origin === PmAssignmentOrigin::CATEGORY
                && $assignment->is_active
                && (! $coveringRuleIds->contains($assignment->pm_rule_id) || ! $this->isEligible($asset));

            if ($stale) {
                $this->withdraw($assignment, $outcome);
            }
        }

        app(AuditLogger::class)->log('asset.pm_categories_reconciled', $asset, [], $outcome);

        return $outcome;
    }

    /**
     * @param  array{created: int, restored: int, withdrawn: int, skipped: int}  $outcome
     */
    private function ensureCovered(PmRule $rule, Asset $asset, ?AssetPmAssignment $assignment, array &$outcome): void
    {
        if ($assignment === null) {
            $this->create->execute(
                $asset,
                $rule,
                null,
                PmAssignmentOrigin::CATEGORY,
                $asset->maintenance_category_id,
            );
            $outcome['created']++;

            return;
        }

        if ($assignment->is_active) {
            return;
        }

        // A person switched this off deliberately, or it is a manual row.
        // A category link does not outrank either.
        if ($assignment->origin !== PmAssignmentOrigin::CATEGORY || $assignment->deactivated_by !== null) {
            $outcome['skipped']++;

            return;
        }

        $this->reactivate->execute($assignment, null);
        $outcome['restored']++;
    }

    /**
     * @param  array{created: int, restored: int, withdrawn: int, skipped: int}  $outcome
     */
    private function withdraw(AssetPmAssignment $assignment, array &$outcome): void
    {
        try {
            $this->deactivate->execute($assignment, null);
            $outcome['withdrawn']++;
        } catch (DomainException) {
            // An open MR/WO still hangs off this assignment. Leaving it live is
            // the safe answer — the chain finishes, and the next run withdraws it.
            $outcome['skipped']++;
        }
    }

    /**
     * @param  Collection<int, int>  $categoryIds
     * @return Builder<Asset>
     */
    private function eligibleAssets(Collection $categoryIds): Builder
    {
        return Asset::query()
            ->whereIn('maintenance_category_id', $categoryIds)
            ->where('is_active', true)
            ->where('maintenance_status', MaintenanceStatus::ENROLLED);
    }

    private function isEligible(Asset $asset): bool
    {
        return $asset->is_active && $asset->maintenance_status === MaintenanceStatus::ENROLLED;
    }
}
