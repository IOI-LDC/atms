<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\MasterDataItem;
use App\Models\UsageReadingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MasterDataController extends Controller
{
    public function indexLocations(): JsonResponse
    {
        Gate::authorize('manage', Location::class);

        return response()->json(['data' => Location::all()]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        Gate::authorize('manage', Location::class);

        $validated = $request->validate([
            'parent_id' => ['nullable', 'exists:locations,id'],
            'name' => ['required', 'string'],
            'type' => ['required', 'string'],
            'code' => ['required', 'string', 'min:2', 'max:3', 'unique:locations,code'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $location = Location::create($validated);

        return response()->json(['data' => $location], 201);
    }

    public function updateLocation(Request $request, Location $location): JsonResponse
    {
        Gate::authorize('manage', Location::class);

        $validated = $request->validate([
            'parent_id' => ['nullable', 'exists:locations,id'],
            'name' => ['string'],
            'type' => ['string'],
            'code' => [
                'nullable',
                'string',
                'min:2',
                'max:3',
                Rule::unique('locations', 'code')->ignore($location->id),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $location->update($validated);

        return response()->json(['data' => $location]);
    }

    public function indexMasterData(string $groupKey): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);
        $this->guardManagedGroup($groupKey);

        $items = MasterDataItem::where('group_key', $groupKey)->orderBy('sort_order')->get();

        return response()->json(['data' => $items]);
    }

    public function storeMasterDataItem(Request $request, string $groupKey): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);
        $this->guardManagedGroup($groupKey);

        $validated = $request->validate([
            'value' => [
                'required', 'string', 'max:64',
                // Stored values are referenced by other tables, so they follow
                // the house convention rather than free text.
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('master_data_items', 'value')->where('group_key', $groupKey),
            ],
            'label' => ['required', 'string', 'max:255'],
            'sort_order' => ['integer'],
            'is_active' => ['boolean'],
        ]);

        $validated['group_key'] = $groupKey;

        $item = MasterDataItem::create($validated);

        return response()->json(['data' => $item], 201);
    }

    public function updateMasterDataItem(Request $request, MasterDataItem $item): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);
        $this->guardManagedGroup($item->group_key);

        // `value` is deliberately absent: it is what other tables store, so
        // editing it orphans every record pointing at the old string
        // (`assets.condition_status`, `maintenance_requests.priority`). Renaming
        // is what `label` is for — that is the half users actually read.
        $validated = $request->validate([
            'label' => ['string', 'max:255'],
            'sort_order' => ['integer'],
            'is_active' => ['boolean'],
        ]);

        // Mirrors the Unclassified-category guard: the default is what automatic
        // resets resolve to, so deactivating it would leave those writes with
        // nothing to write.
        //
        // The message deliberately does not say "make another value the default
        // first" — no endpoint accepts `is_default`, so that would send an Admin
        // looking for a control that does not exist. Renaming is the one thing
        // they *can* do here, so that is what it offers. Moving the default is
        // 🟠 D-023, triggered the day LDC wants a reset target other than Normal.
        if ($item->is_default && ($validated['is_active'] ?? true) === false) {
            throw ValidationException::withMessages([
                'is_active' => "\"{$item->label}\" is the default for this list and cannot be deactivated. Rename it if the wording is wrong.",
            ]);
        }

        $item->update($validated);

        return response()->json(['data' => $item]);
    }

    /**
     * The route takes the group key as a free string, so an unknown one would
     * otherwise silently create a vocabulary nothing reads.
     */
    private function guardManagedGroup(string $groupKey): void
    {
        abort_unless(in_array($groupKey, MasterDataItem::MANAGED_GROUPS, true), 404);
    }

    public function indexUsageReadingTypes(): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);

        return response()->json(['data' => UsageReadingType::all()]);
    }

    public function storeUsageReadingType(Request $request): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);

        $validated = $request->validate([
            'name' => ['required', 'string'],
            'unit' => ['required', 'string'],
            'is_active' => ['boolean'],
        ]);

        $type = UsageReadingType::create($validated);

        return response()->json(['data' => $type], 201);
    }

    public function updateUsageReadingType(Request $request, UsageReadingType $type): JsonResponse
    {
        Gate::authorize('manage', MasterDataItem::class);

        $validated = $request->validate([
            'name' => ['string'],
            'unit' => ['string'],
            'is_active' => ['boolean'],
        ]);

        $type->update($validated);

        return response()->json(['data' => $type]);
    }
}
