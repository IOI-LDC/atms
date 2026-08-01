<?php

namespace App\Actions\Assets;

use App\Jobs\ReconcilePmCategoryAssignmentsJob;
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

            // Category-driven PM follows the asset. Deactivation and withdrawal
            // matter as much as a category change: both take the asset out of
            // the population a category link may expand onto.
            $affectsPmCoverage = ['maintenance_category_id', 'is_active', 'maintenance_status'];

            foreach ($affectsPmCoverage as $field) {
                if (array_key_exists($field, $fieldUpdates) && ($before[$field] ?? null) !== ($after[$field] ?? null)) {
                    DB::afterCommit(fn () => dispatch(ReconcilePmCategoryAssignmentsJob::forAsset($asset->id)));
                    break;
                }
            }

            return $asset;
        });
    }
}
