<?php

namespace App\Queries\Assets;

use App\Enums\RoleCode;
use App\Exceptions\InvalidSizeFormatException;
use App\Models\Asset;
use App\Support\Size;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

class AssetIndexQuery
{
    /** `erp_asset_code` is not sortable — it is not user-facing. */
    protected array $allowedSorts = [
        'name' => 'name',
        'asset_tag' => 'asset_tag',
        'serial_number' => 'serial_number',
        'size' => 'size_inches',
        'operational_status' => 'operational_status',
        'created_at' => 'created_at',
    ];

    public function build(Request $request): CursorPaginator
    {
        $user = $request->user();
        $query = Asset::query()->with(['currentLocation', 'maintenanceCategory']);

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
            //
            // Covers everything the identity package shows — name, serial,
            // size, Maintenance Category — plus Asset Tag. `erp_asset_code` is
            // deliberately NOT searchable. Nullable columns are safe here:
            // LOWER(NULL) LIKE ? is NULL, which just fails to match in the OR.
            $raw = $request->input('search');
            $term = '%'.strtolower($raw).'%';

            $query->where(function ($q) use ($raw, $term) {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(serial_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(asset_tag) LIKE ?', [$term])
                    ->orWhereHas('maintenanceCategory', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]));

                // Size matches exactly, on the canonical numeric value, so
                // "6 3/4", "6.75" and '6 3/4"' all find the same assets. A term
                // that is not a whole valid size (e.g. "6 3") contributes no
                // size clause rather than matching loosely.
                if ($size = $this->parseSize($raw)) {
                    $q->orWhere('size_inches', $size->canonical());
                }
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('operational_status')) {
            $query->where('operational_status', $request->input('operational_status'));
        }

        if ($request->filled('maintenance_status')) {
            $query->where('maintenance_status', $request->input('maintenance_status'));
        }

        if ($request->filled('location_id')) {
            $query->where('current_location_id', $request->input('location_id'));
        }
    }

    /**
     * Interpret a search term as an exact inch measurement, or null if it is
     * not one. Shared shape with PartIndexQuery.
     */
    protected function parseSize(string $term): ?Size
    {
        try {
            return Size::fromWorkbookCell(trim($term));
        } catch (InvalidSizeFormatException) {
            return null;
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
