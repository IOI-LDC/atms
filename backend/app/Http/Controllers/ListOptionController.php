<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\FaSubclassTypeCode;
use App\Models\MaintenanceCategory;
use App\Models\MasterDataItem;
use App\Models\Part;
use App\Models\UsageReadingType;
use App\Support\Size;
use Illuminate\Http\JsonResponse;

class ListOptionController extends Controller
{
    /**
     * Return active-only list options for a given group.
     *
     * Public read path (authenticated, not admin-gated by design) so non-Admin
     * roles can populate dropdowns: MR priorities (MR create = everyone),
     * reading types, and the FA-subclass filter (Assets list = all roles).
     * Admin writes still go through the existing admin-gated master-data CRUD.
     */
    public function index(string $group): JsonResponse
    {
        return match ($group) {
            'maintenance_priorities' => $this->maintenancePriorities(),
            'usage_reading_types' => $this->usageReadingTypes(),
            'fa_subclass_type_codes' => $this->faSubclassTypeCodes(),
            'maintenance_categories' => $this->maintenanceCategories(),
            'asset_sizes' => $this->assetSizes(),
            default => abort(404),
        };
    }

    private function maintenancePriorities(): JsonResponse
    {
        $items = MasterDataItem::where('group_key', 'maintenance_priorities')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'value', 'label', 'sort_order']);

        return response()->json(['data' => $items]);
    }

    private function usageReadingTypes(): JsonResponse
    {
        $items = UsageReadingType::where('is_active', true)
            ->get(['id', 'name', 'unit']);

        return response()->json(['data' => $items]);
    }

    /**
     * The controlled Maintenance Category vocabulary, for assigning a category
     * to an asset or part.
     *
     * Active-only dropdown list. The vocabulary itself is managed by Admins via
     * the `MaintenanceCategoryController` CRUD (and seeded by the workbook
     * import); assigning an existing value to a record is a separate thing and
     * is allowed for all roles.
     */
    private function maintenanceCategories(): JsonResponse
    {
        $items = MaintenanceCategory::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return response()->json(['data' => $items]);
    }

    /**
     * Sizes already in use across the asset and part catalogues, as a controlled
     * picker list.
     *
     * Derived rather than a fixed table: an arbitrary list of every 1/32
     * increment would be thousands of entries, and the workbook import is what
     * establishes which sizes the business actually holds. `value` is the exact
     * canonical decimal for storage; `label` is O&G fractional notation.
     */
    private function assetSizes(): JsonResponse
    {
        $canonical = Asset::query()->whereNotNull('size_inches')->distinct()->pluck('size_inches')
            ->merge(Part::query()->whereNotNull('size_inches')->distinct()->pluck('size_inches'))
            ->map(fn ($size) => $size instanceof Size ? $size->canonical() : (string) $size)
            ->unique()
            ->sort(fn (string $a, string $b) => (float) $a <=> (float) $b)
            ->values();

        $items = $canonical->map(fn (string $value) => [
            'value' => $value,
            'label' => Size::fromCanonical($value)->format(),
        ]);

        return response()->json(['data' => $items]);
    }

    private function faSubclassTypeCodes(): JsonResponse
    {
        $items = FaSubclassTypeCode::orderBy('fa_subclass_code')
            ->get(['fa_subclass_code', 'type_code', 'description', 'has_no_physical_size']);

        return response()->json(['data' => $items]);
    }
}
