<?php

namespace App\Queries\Dashboard;

use App\Models\AssetLocationHistory;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Most recent asset relocations within the rolling window.
 *
 * @return Collection<int, AssetLocationHistory>
 */
class RecentlyRelocatedAssetsQuery
{
    public function handle(Carbon $since, Carbon $now, int $limit = 5): Collection
    {
        return AssetLocationHistory::whereBetween('effective_at', [$since, $now])
            ->with(['asset', 'fromLocation', 'toLocation', 'changedBy'])
            ->orderByDesc('effective_at')
            ->limit($limit)
            ->get();
    }
}
