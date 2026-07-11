<?php

namespace App\Queries\Assets;

use App\Enums\RoleCode;
use App\Models\Asset;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

class AssetIndexQuery
{
    protected array $allowedSorts = [
        'name' => 'name',
        'erp_asset_code' => 'erp_asset_code',
        'category' => 'category',
        'operational_status' => 'operational_status',
        'created_at' => 'created_at',
    ];

    public function build(Request $request): CursorPaginator
    {
        $user = $request->user();
        $query = Asset::query()->with('currentLocation');

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
            // LOWER() on both sides keeps matching case-insensitive on every
            // supported driver. Plain LIKE is case-sensitive on PostgreSQL;
            // ILIKE is not valid on SQLite. See ticket: case-sensitive search.
            $term = '%'.strtolower($request->input('search')).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(erp_asset_code) LIKE ?', [$term]));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('operational_status')) {
            $query->where('operational_status', $request->input('operational_status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('location_id')) {
            $query->where('current_location_id', $request->input('location_id'));
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
