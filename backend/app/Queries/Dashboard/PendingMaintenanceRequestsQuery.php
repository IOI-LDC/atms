<?php

namespace App\Queries\Dashboard;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\RoleCode;
use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @return array{count: int, items: Collection<int, MaintenanceRequest>}
 */
class PendingMaintenanceRequestsQuery
{
    public function handle(User $user): array
    {
        $query = MaintenanceRequest::with(['asset', 'createdBy', 'workOrder', 'attachments'])
            ->where('status', MaintenanceRequestStatus::PENDING_REVIEW);

        $this->applyRoleScoping($query, $user);

        return [
            'count' => (clone $query)->count(),
            'items' => (clone $query)->orderByDesc('created_at')->limit(5)->get(),
        ];
    }

    protected function applyRoleScoping(Builder $query, User $user): void
    {
        if ($user->hasRole(RoleCode::REQUESTER)) {
            $query->where('created_by', $user->id);
        }
    }
}
