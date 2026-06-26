<?php

namespace App\Queries\Dashboard;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * @return array{count: int, items: Collection<int, WorkOrder>}
 */
class RecentlyClosedWorkOrdersQuery
{
    public function handle(): array
    {
        $query = WorkOrder::with(['asset', 'assignedTo', 'maintenanceRequest', 'parts.part', 'attachments'])
            ->where('status', WorkOrderStatus::CLOSED)
            ->where('closed_at', '>=', now()->subDays(30));

        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)->orderByDesc('closed_at')->limit(5)->get(),
        ];
    }
}
