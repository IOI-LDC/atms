<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\Location;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateAssetLocation
{
    public function execute(Asset $asset, Location $toLocation, ?string $reason = null, ?string $notes = null, ?int $changedByUserId = null): Asset
    {
        return DB::transaction(function () use ($asset, $toLocation, $reason, $notes, $changedByUserId) {
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
                $lockedAsset->save();

                $after = $lockedAsset->fresh()->toArray();
                $logger->log('asset.location_updated', $lockedAsset, $before, $after);
            }

            return $lockedAsset;
        });
    }
}
