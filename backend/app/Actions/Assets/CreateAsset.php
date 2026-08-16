<?php

namespace App\Actions\Assets;

use App\Enums\OperationalStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\MasterDataItem;
use App\Services\Audit\AuditLogger;
use App\Support\Assets\AssetFieldStatus;
use Illuminate\Support\Facades\DB;

class CreateAsset
{
    public function execute(array $data): Asset
    {
        return DB::transaction(function () use ($data) {
            $logger = app(AuditLogger::class);

            $location = ! empty($data['current_location_id'])
                ? Location::find($data['current_location_id'])
                : null;

            // An asset created directly at a rig or well site is already out
            // there. Derived from the location for the same reason every later
            // move is: `at_the_field` is never a caller's to choose, and an
            // asset whose location says "rig" while its status says "ready for
            // field" is a lie the utilisation report would repeat.
            $operationalStatus = AssetFieldStatus::isFieldLocation($location)
                ? OperationalStatus::AT_THE_FIELD->value
                : ($data['operational_status'] ?? OperationalStatus::READY_FOR_FIELD->value);

            $asset = Asset::create([
                'erp_asset_code' => $data['erp_asset_code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'model' => $data['model'] ?? null,
                'manufacturer' => $data['manufacturer'] ?? null,
                'operational_status' => $operationalStatus,
                // Resolved from the vocabulary, not hardcoded — Admins own which
                // value is the default. Null only if the group is missing entirely,
                // which the 4a seed rules out on any migrated database.
                'condition_status' => $data['condition_status']
                    ?? MasterDataItem::defaultFor(MasterDataItem::ASSET_CONDITIONS)?->value,
                'current_location_id' => $data['current_location_id'] ?? null,
                'is_active' => true,
            ]);

            // Record initial placement if a location was provided.
            if (! empty($data['current_location_id'])) {
                $asset->locationHistories()->create([
                    'from_location_id' => null,
                    'to_location_id' => $data['current_location_id'],
                    'effective_at' => now(),
                    'reason' => 'Initial placement',
                    'notes' => null,
                    'changed_by_user_id' => auth()->id(),
                ]);
            }

            $logger->log('asset.created', $asset, [], $asset->fresh()->toArray());

            return $asset;
        });
    }
}
