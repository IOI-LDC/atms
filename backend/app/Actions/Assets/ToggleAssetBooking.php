<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class ToggleAssetBooking
{
    public function execute(Asset $asset, bool $book): Asset
    {
        if (! $asset->is_active) {
            throw new DomainException('Cannot book an inactive asset.');
        }

        if ($book && $asset->is_booked) {
            throw new DomainException('Asset is already booked.');
        }

        if (! $book && ! $asset->is_booked) {
            throw new DomainException('Asset is not booked.');
        }

        return DB::transaction(function () use ($asset, $book) {
            $logger = app(AuditLogger::class);

            $before = $asset->toArray();
            $asset->update(['is_booked' => $book]);
            $after = $asset->fresh()->toArray();

            $logger->log(
                $book ? 'asset.booked' : 'asset.unbooked',
                $asset,
                $before,
                $after
            );

            return $asset->fresh();
        });
    }
}
