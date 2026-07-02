<?php

namespace App\Http\Controllers;

use App\Models\FaSubclassTypeCode;
use App\Models\MasterDataItem;
use App\Models\UsageReadingType;
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

    private function faSubclassTypeCodes(): JsonResponse
    {
        $items = FaSubclassTypeCode::orderBy('fa_subclass_code')
            ->get(['fa_subclass_code', 'type_code', 'description', 'has_no_physical_size']);

        return response()->json(['data' => $items]);
    }
}
