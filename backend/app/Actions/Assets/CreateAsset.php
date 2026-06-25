<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateAsset
{
    public function execute(array $data): Asset
    {
        return DB::transaction(function () use ($data) {
            $logger = app(AuditLogger::class);

            $asset = Asset::create([
                'erp_asset_code' => $data['erp_asset_code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'serial_number' => $data['serial_number'] ?? null,
                'model' => $data['model'] ?? null,
                'manufacturer' => $data['manufacturer'] ?? null,
                'operational_status' => $data['operational_status'] ?? 'active',
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
