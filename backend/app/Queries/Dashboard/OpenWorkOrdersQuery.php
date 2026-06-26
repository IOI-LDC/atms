<?php

namespace App\Queries\Dashboard;

use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @return array{count: int, items: Collection<int, WorkOrder>}
 */
class OpenWorkOrdersQuery
{
    public function handle(User $user): array
    {
        $query = WorkOrder::with(['asset', 'assignedTo', 'maintenanceRequest', 'parts.part', 'attachments'])
            ->whereIn('status', [WorkOrderStatus::OPEN, WorkOrderStatus::IN_PROGRESS]);

        $this->applyRoleScoping($query, $user);

        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)->orderByDesc('created_at')->limit(5)->get(),
        ];
    }

    protected function applyRoleScoping(Builder $query, User $user): void
    {
        if ($user->hasRole(RoleCode::TECHNICIAN)) {
            $query->where('assigned_to_user_id', $user->id);
        }
    }
}
