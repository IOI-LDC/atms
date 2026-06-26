<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class UpdateAssetFields
{
    /**
     * @param  array<string, mixed>  $fieldUpdates
     */
    public function execute(Asset $asset, array $fieldUpdates): Asset
    {
        if (array_key_exists('asset_tag', $fieldUpdates) && $fieldUpdates['asset_tag'] !== null) {
            $fieldUpdates['asset_tag_generated_at'] = $asset->asset_tag_generated_at ?? now();
        }

        if (empty($fieldUpdates)) {
            return $asset;
        }

        return DB::transaction(function () use ($asset, $fieldUpdates) {
            $logger = app(AuditLogger::class);
            $before = $asset->toArray();

            try {
                $asset->update($fieldUpdates);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'unique constraint') || str_contains($e->getMessage(), '23505')) {
                    throw new DomainException('The generated asset tag is already in use.');
                }

                throw $e;
            }

            $after = $asset->fresh()->toArray();
            $logger->log('asset.updated', $asset, $before, $after);

            return $asset;
        });
    }
}
