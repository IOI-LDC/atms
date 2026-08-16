<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\Location;
use App\Services\Audit\AuditLogger;
use App\Support\Assets\AssetFieldStatus;
use Illuminate\Support\Facades\DB;

class UpdateAssetLocation
{
    /**
     * @param  bool  $applyStatusRules  Whether this move may change the asset's
     *                                  operational status and condition. False
     *                                  for workflow-driven moves — a work order
     *                                  owns the status of the asset it is about
     *                                  to work on. {@see AssetFieldStatus}
     */
    public function execute(
        Asset $asset,
        Location $toLocation,
        ?string $reason = null,
        ?string $notes = null,
        ?int $changedByUserId = null,
        bool $applyStatusRules = true
    ): Asset {
        return DB::transaction(function () use ($asset, $toLocation, $reason, $notes, $changedByUserId, $applyStatusRules) {
            $logger = app(AuditLogger::class);

            // Lock asset row for update to prevent race conditions during location change
            $lockedAsset = Asset::where('id', $asset->id)->lockForUpdate()->first();

            $before = $lockedAsset->toArray();
            $fromLocationId = $lockedAsset->current_location_id;

            if ($fromLocationId !== $toLocation->id) {
                $lockedAsset->locationHistories()->create([
                    'from_location_id' => $fromLocationId,
                    'to_location_id' => $toLocation->id,
                    'effective_at' => now(),
                    'reason' => $reason,
                    'notes' => $notes,
                    'changed_by_user_id' => $changedByUserId,
                ]);

                $lockedAsset->current_location_id = $toLocation->id;

                // Resolved from the row we hold the lock on, so a concurrent
                // status change cannot slip between the read and the write.
                $transition = AssetFieldStatus::transitionFor($lockedAsset, $toLocation, $applyStatusRules);

                foreach ($transition['attributes'] as $attribute => $value) {
                    $lockedAsset->{$attribute} = $value;
                }

                $lockedAsset->save();

                $after = $lockedAsset->fresh()->toArray();
                $logger->log('asset.location_updated', $lockedAsset, $before, $after);

                if ($transition['attributes'] !== []) {
                    $logger->log('asset.status_updated', $lockedAsset, $before, $after, [
                        'source' => 'location_change',
                        'to_location_id' => $toLocation->id,
                    ]);
                }

                // Audited rather than thrown: the asset has physically moved, and
                // refusing to record that because a vocabulary row is missing
                // would lose the more important fact. Surfaces in the audit log
                // as a data-quality event someone can act on.
                if ($transition['warning'] !== null) {
                    $logger->log('asset.condition_flag_skipped', $lockedAsset, [], [], [
                        'reason' => $transition['warning'],
                        'expected_condition' => AssetFieldStatus::NEED_INSPECTION,
                    ]);
                }
            }

            return $lockedAsset;
        });
    }
}
