<?php

namespace App\Queries\MaintenanceRequests;

use App\Enums\RoleCode;
use App\Models\MaintenanceRequest;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

class MaintenanceRequestIndexQuery
{
    protected array $allowedSorts = [
        'created_at' => 'created_at',
        'priority' => 'priority',
        'status' => 'status',
    ];

    public function build(Request $request): CursorPaginator
    {
        $user = $request->user();
        $query = MaintenanceRequest::with(['asset', 'createdBy', 'workOrder', 'attachments']);

        $this->applyRoleScoping($query, $user);
        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        $perPage = min((int) $request->input('per_page', 25), 5000);

        return $query->cursorPaginate($perPage);
    }

    protected function applyRoleScoping($query, $user): void
    {
        if ($user->hasRole(RoleCode::REQUESTER)) {
            $query->where('created_by', $user->id);
        }
    }

    protected function applyFilters($query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->input('asset_id'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('created_by')) {
            $query->where('created_by', $request->input('created_by'));
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
