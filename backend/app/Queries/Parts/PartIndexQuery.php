<?php

namespace App\Queries\Parts;

use App\Enums\RoleCode;
use App\Exceptions\InvalidSizeFormatException;
use App\Models\Asset;
use App\Models\Part;
use App\Support\Size;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

class PartIndexQuery
{
    /** `erp_part_code` is not sortable — it is not user-facing. */
    protected array $allowedSorts = [
        'name' => 'name',
        'part_number' => 'part_number',
        'size' => 'size_inches',
        'available_quantity' => 'available_quantity',
    ];

    public function build(Request $request): CursorPaginator
    {
        $user = $request->user();
        $query = Part::query()->with('maintenanceCategory');

        $this->applyRoleScoping($query, $user);
        $this->applyFilters($query, $request);

        // The compatibility filter installs its own specificity ordering, which
        // must stay primary — a caller-supplied sort would otherwise become a
        // meaningless third-level tiebreak.
        if (! $request->filled('compatible_with_asset_id')) {
            $this->applySort($query, $request);
        }

        $perPage = min((int) $request->input('per_page', 25), 5000);

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
        // Compatibility context for the Work Order part picker. Narrowing here
        // rather than in the client is what stops an incompatible part being
        // offered; RecordWorkOrderPart repeats the check on submit so a direct
        // API call cannot bypass it. Only active parts are ever offered, which
        // is stricter than the role scoping above.
        if ($request->filled('compatible_with_asset_id')) {
            $asset = Asset::findOrFail($request->input('compatible_with_asset_id'));

            $query->compatibleWith($asset)->where('is_active', true);
            $this->applySpecificityOrdering($query, $asset);
        }

        if ($request->filled('search')) {
            // LOWER() on both sides keeps matching case-insensitive on every
            // supported driver. Plain LIKE is case-sensitive on PostgreSQL;
            // ILIKE is not valid on SQLite. See ticket: case-sensitive search.
            //
            // Covers what the identity package shows — name, supplier Part
            // Number, size, Maintenance Category. `erp_part_code` is
            // deliberately NOT searchable.
            $raw = $request->input('search');
            $term = '%'.strtolower($raw).'%';

            $query->where(function ($q) use ($raw, $term) {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(part_number) LIKE ?', [$term])
                    ->orWhereHas('maintenanceCategory', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]));

                // Exact canonical size match — see AssetIndexQuery::parseSize.
                if ($size = $this->parseSize($raw)) {
                    $q->orWhere('size_inches', $size->canonical());
                }
            });
        }
    }

    /**
     * Order compatible parts most-specific first.
     *
     *   1. category AND size both match the asset — the strongest candidate
     *   2. size matches, category blank — size is the hard physical constraint,
     *      so it outranks a category match
     *   3. category matches, size blank — "fits all motors" style
     *   4. both blank — a universal part, the weakest candidate
     *
     * Within a bucket, in-stock parts come before out-of-stock ones so a
     * disabled option never outranks one that can actually be requested, then
     * name for a stable, readable order.
     *
     * When the asset itself lacks a category or size, the equality arms are NULL
     * rather than true, so buckets 1–3 empty out and only universal parts remain
     * — matching the compatibility rule without any special-casing.
     */
    protected function applySpecificityOrdering($query, Asset $asset): void
    {
        $categoryId = $asset->maintenance_category_id;
        $size = $asset->size_inches?->canonical();

        $query
            ->orderByRaw(
                'CASE
                    WHEN maintenance_category_id = ? AND size_inches = ? THEN 1
                    WHEN maintenance_category_id IS NULL AND size_inches = ? THEN 2
                    WHEN maintenance_category_id = ? AND size_inches IS NULL THEN 3
                    ELSE 4
                END',
                [$categoryId, $size, $size, $categoryId],
            )
            ->orderByRaw('CASE WHEN available_quantity > 0 THEN 0 ELSE 1 END')
            ->orderBy('name');
    }

    /**
     * Interpret a search term as an exact inch measurement, or null if it is
     * not one. Mirrors AssetIndexQuery::parseSize.
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
        $sort = $request->input('sort', 'name:asc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');

        if (isset($this->allowedSorts[$field])) {
            $query->orderBy($this->allowedSorts[$field], $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('name', 'asc');
        }
    }
}
