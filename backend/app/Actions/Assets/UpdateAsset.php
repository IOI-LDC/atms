<?php

namespace App\Actions\Assets;

use App\Exceptions\AssetTagConflictException;
use App\Exceptions\InactiveLocationException;
use App\Models\Asset;
use App\Models\Location;
use App\Services\Audit\AuditLogger;
use App\Support\Assets\AssetFieldStatus;
use App\Support\Assets\AssetWorkEligibility;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * `PATCH /assets/{id}` — the whole update, in one transaction.
 *
 * The controller used to call {@see UpdateAssetLocation} and then
 * {@see UpdateAssetFields} back to back, each opening its own transaction. A
 * failure in the second — an asset-tag collision is the realistic one — left the
 * first committed, so a request that came back 409 had already moved the asset
 * and written a location-history row. Composing them here makes the whole PATCH
 * atomic; the two actions keep their own transactions, which nest as savepoints
 * and roll back with this one.
 *
 * Three rules live here rather than in {@see UpdateAssetLocation}, because that
 * action is also what `StartWorkOrder` calls and none of them may apply to a
 * work order moving an asset it owns:
 *
 *  1. **Eligibility.** A withdrawn or deactivated asset cannot be relocated by
 *     hand — the same guard `POST /assets/{id}/location` applies. Scoped to the
 *     location branch alone: the rest of the PATCH must keep working on a
 *     withdrawn asset, or there would be no way to reactivate one.
 *  2. **Q1 manual-move rules.** {@see AssetFieldStatus::guardManualMove()}.
 *  3. **Location-derived status wins.** See {@see self::execute()}.
 */
class UpdateAsset
{
    /**
     * Columns this action may write. `location_notes` is deliberately absent —
     * it belongs to the location-history row, not to the asset.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'name', 'description', 'fa_subclass_code', 'maintenance_category_id', 'size_inches',
        'serial_number', 'model', 'manufacturer', 'operational_status', 'condition_status',
        'is_active', 'asset_tag', 'asset_tag_override_reason', 'maintenance_status', 'asset_kind',
    ];

    public function __construct(
        private readonly UpdateAssetLocation $locationAction,
        private readonly UpdateAssetFields $fieldsAction,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws DomainException eligibility or manual-move failure (409)
     * @throws InactiveLocationException unusable destination (422)
     * @throws AssetTagConflictException tag collision (409, field-keyed)
     */
    public function execute(Asset $asset, array $validated, int $actorUserId): Asset
    {
        return DB::transaction(function () use ($asset, $validated, $actorUserId) {
            $locked = Asset::where('id', $asset->id)->lockForUpdate()->first();

            $fieldUpdates = array_intersect_key($validated, array_flip(self::FIELDS));

            $locationChanged = array_key_exists('current_location_id', $validated)
                && $validated['current_location_id'] !== $locked->current_location_id;

            if (! $locationChanged) {
                return $this->fieldsAction->execute($locked, $fieldUpdates);
            }

            // An asset always sits somewhere. `current_location_id` is nullable
            // in the request only because the key is optional; sending an
            // explicit null is not a way to un-place an asset.
            if ($validated['current_location_id'] === null) {
                throw new InactiveLocationException('An asset cannot be left without a location.');
            }

            $location = Location::find($validated['current_location_id']);

            if ($location === null || ! $location->is_active) {
                throw new InactiveLocationException('Cannot assign an inactive location.');
            }

            AssetWorkEligibility::guard($locked, 'update location');
            AssetFieldStatus::guardManualMove($locked, $location);

            $statusBefore = $locked->operational_status;

            $locked = $this->locationAction->execute(
                $locked,
                $location,
                null,
                $validated['location_notes'] ?? null,
                $actorUserId
            );

            // ⚠️ Load-bearing: the location move runs FIRST, so a submitted
            // `operational_status` would otherwise overwrite what the move just
            // derived. Moving an asset to a rig makes it `at_the_field`; an edit
            // form that also echoes back the status it was showing before the
            // move would put it straight back to `ready_for_field`, leaving the
            // asset on a rig and claiming to be on base.
            //
            // The derived value wins because it is a fact about where the asset
            // physically is, and because `at_the_field` is derived-never-chosen
            // (see OperationalStatus). The discard is audited rather than
            // silent: an API client that meant it deserves to find out why its
            // value did not stick.
            //
            // `condition_status` is deliberately NOT discarded the same way —
            // it is a value a person chooses, so an explicit one in the same
            // request is a deliberate override of the move's `need_inspection`
            // flag, not a stale echo.
            if ($locked->operational_status !== $statusBefore && array_key_exists('operational_status', $fieldUpdates)) {
                if ($fieldUpdates['operational_status'] !== $locked->operational_status?->value) {
                    app(AuditLogger::class)->log('asset.status_payload_discarded', $locked, [], [], [
                        'submitted' => $fieldUpdates['operational_status'],
                        'applied' => $locked->operational_status?->value,
                        'reason' => 'derived_from_location_change',
                        'to_location_id' => $location->id,
                    ]);
                }

                unset($fieldUpdates['operational_status']);
            }

            return $this->fieldsAction->execute($locked, $fieldUpdates);
        });
    }
}
