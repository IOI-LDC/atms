<?php

namespace App\Support\Assets;

use App\Enums\AssetDeployment;
use App\Enums\OperationalStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MasterDataItem;

/**
 * The location-derived half of an asset's operational status (Q1/Q2, 2026-08-16).
 *
 * `at_the_field` is the one operational status nobody picks. It is written when
 * an asset moves somewhere `AssetDeployment` calls DEPLOYED, and cleared when it
 * comes back. Keying off {@see AssetDeployment::forLocationType()} rather than a
 * literal `rig`/`well_site` list matters: that enum is the declared single source
 * of truth for "out for work", and a new deployed location type must not need a
 * second edit here to be recognised.
 *
 * ⚠️ **Only user-initiated moves apply these rules.** A work order moving an
 * asset to the workshop passes `$applyStatusRules = false`, because the work
 * order's own lifecycle owns the status at that point — `StartWorkOrder` forces
 * `under_maintenance` immediately afterwards, and letting the location rule fire
 * first would both fight it and stamp a spurious inspection flag.
 *
 * The Q2 exit rule stamps `need_inspection` because an asset coming back from a
 * rig has been out of anyone's sight and should be looked at before it goes out
 * again. That is *not* wanted when a work order caused the move: a technician is
 * about to examine it anyway, and closing the work order would clear the flag in
 * the same breath (confirmed with the user 2026-08-16, decision D6).
 */
class AssetFieldStatus
{
    /**
     * The condition an asset returning from the field is flagged with. Seeded by
     * `2026_08_16_000002_seed_asset_conditions_vocabulary`.
     */
    public const NEED_INSPECTION = 'need_inspection';

    public static function isFieldLocation(?Location $location): bool
    {
        return $location !== null
            && AssetDeployment::forLocationType($location->type) === AssetDeployment::DEPLOYED;
    }

    /**
     * Q1: which manual location moves a person may make.
     *
     * Applied at the two user-facing entry points (`PATCH /assets/{id}` and
     * `POST /assets/{id}/location`) and **never inside `UpdateAssetLocation`** —
     * `StartWorkOrder` calls that same action, and a gate placed there would
     * make corrective work orders impossible to start on exactly the assets that
     * need them most.
     *
     *  - `ready_for_field` — free to move anywhere.
     *  - `at_the_field` — may only come back to a yard or building. Going to a
     *    workshop is a work order's job, and going straight to another rig would
     *    skip the inspection the return is supposed to trigger.
     *  - `failure` / `under_maintenance` — not movable by hand. Where a broken
     *    or in-progress asset goes is decided by the work order that owns it.
     *
     * @throws \DomainException
     */
    public static function guardManualMove(Asset $asset, Location $toLocation): void
    {
        $status = $asset->operational_status;

        // No recorded status is not a reason to block a move — it is a data gap,
        // and blocking would make it unfixable through the UI.
        if ($status === null || $status === OperationalStatus::READY_FOR_FIELD) {
            return;
        }

        if ($status === OperationalStatus::AT_THE_FIELD) {
            if (AssetDeployment::forLocationType($toLocation->type) === AssetDeployment::IDLE) {
                return;
            }

            throw new \DomainException(
                'An asset at the field can only be moved back to a yard or building. '
                .'Start a work order to bring it into a workshop.'
            );
        }

        throw new \DomainException(match ($status) {
            OperationalStatus::FAILURE => 'Cannot move an asset in failure by hand. '
                .'Raise a maintenance request so the move is recorded with the work.',
            default => 'Cannot move an asset that is under maintenance. '
                .'Its work order decides where it goes.',
        });
    }

    /**
     * Attribute changes implied by moving $asset to $toLocation.
     *
     * Returns an empty `attributes` array when the move implies nothing — which
     * is the common case, including every move between two non-field locations.
     *
     * `warning` is non-null only when a rule could not be fully applied. It is
     * never a reason to abandon the move: an asset physically returning from a
     * rig has returned whether or not ATMS can name its condition.
     *
     * @param  bool  $applyStatusRules  false for workflow-driven moves (work-order start)
     * @return array{attributes: array<string, mixed>, warning: ?string}
     */
    public static function transitionFor(Asset $asset, Location $toLocation, bool $applyStatusRules = true): array
    {
        $none = ['attributes' => [], 'warning' => null];

        if (! $applyStatusRules) {
            return $none;
        }

        // Entering the field.
        if (self::isFieldLocation($toLocation)) {
            return $asset->operational_status === OperationalStatus::AT_THE_FIELD
                ? $none
                : ['attributes' => ['operational_status' => OperationalStatus::AT_THE_FIELD], 'warning' => null];
        }

        // Leaving it. Anything that was not `at_the_field` is untouched — a
        // failed asset moving between two yards stays failed.
        if ($asset->operational_status !== OperationalStatus::AT_THE_FIELD) {
            return $none;
        }

        $attributes = ['operational_status' => OperationalStatus::READY_FOR_FIELD];
        $warning = null;

        // Resolved strictly from the vocabulary. If an Admin has deactivated or
        // deleted the row, the status change still happens and the omission is
        // reported — silently skipping it would leave an asset back from a rig
        // looking as though it had been checked.
        $inspection = MasterDataItem::activeIn(MasterDataItem::ASSET_CONDITIONS)
            ->where('value', self::NEED_INSPECTION)
            ->first();

        if ($inspection !== null) {
            $attributes['condition_status'] = $inspection->value;
        } else {
            $warning = 'Asset returned from the field but could not be flagged for inspection: the "'
                .self::NEED_INSPECTION.'" condition is missing or inactive.';
        }

        return ['attributes' => $attributes, 'warning' => $warning];
    }
}
