<?php

namespace App\Queries\WorkOrders;

use App\Enums\RoleCode;
use App\Models\WorkOrder;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

class WorkOrderIndexQuery
{
    protected array $allowedSorts = [
        'created_at' => 'created_at',
        'priority' => 'priority',
        'status' => 'status',
        'started_at' => 'started_at',
        'closed_at' => 'closed_at',
    ];

    public function build(Request $request): CursorPaginator
    {
        $user = $request->user();
        $query = WorkOrder::with(['asset', 'assignedTo', 'maintenanceRequest', 'assignedBy', 'parts.part', 'attachments']);

        $this->applyRoleScoping($query, $user);
        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->cursorPaginate($perPage);
    }

    protected function applyRoleScoping($query, $user): void
    {
        if ($user->hasRole(RoleCode::TECHNICIAN)) {
            $query->where('assigned_to_user_id', $user->id);
        }
    }

    protected function applyFilters($query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to_user_id', $request->input('assigned_to'));
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->input('asset_id'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }
    }

    protected function applySort($query, Request $request): void
    {
        $sort = $request->input('sort', 'created_at:desc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'desc');

        if (isset($this->allowedSorts[$field])) {
            $query->orderBy($this->allowedSorts[$field], $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('created_at');
        }
    }
}
