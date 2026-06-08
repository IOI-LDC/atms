<?php

namespace App\Queries\Parts;

use App\Enums\RoleCode;
use App\Models\Part;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

class PartIndexQuery
{
    protected array $allowedSorts = [
        'name' => 'name',
        'erp_part_code' => 'erp_part_code',
    ];

    public function build(Request $request): CursorPaginator
    {
        $user = $request->user();
        $query = Part::query();

        $this->applyRoleScoping($query, $user);
        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->cursorPaginate($perPage);
    }

    protected function applyRoleScoping($query, $user): void
    {
        if (! $user->hasRole(RoleCode::ADMINISTRATOR) && ! $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            $query->where('is_active', true);
        }
    }

    protected function applyFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('erp_part_code', 'like', "%{$search}%"));
        }
    }

    protected function applySort($query, Request $request): void
    {
        $sort = $request->input('sort', 'name:asc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');

        if (isset($this->allowedSorts[$field])) {
            $query->orderBy($this->allowedSorts[$field], $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('name', 'asc');
        }
    }
}
