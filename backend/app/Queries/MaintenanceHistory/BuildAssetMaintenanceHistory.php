<?php

namespace App\Queries\MaintenanceHistory;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

class BuildAssetMaintenanceHistory
{
    public function build(Asset $asset, Request $request): CursorPaginator
    {
        $user = $request->user();

        $query = $asset->workOrders()
            ->with(['maintenanceRequest', 'parts.part'])
            ->where('status', WorkOrderStatus::CLOSED);

        if ($user->hasRole(RoleCode::REQUESTER)) {
            $query->whereHas('maintenanceRequest', fn ($q) => $q->where('created_by', $user->id));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->orderByDesc('closed_at')->cursorPaginate($perPage);
    }
}
