<?php

namespace App\Http\Controllers;

use App\Actions\Parts\UpdatePart;
use App\Http\Resources\PartResource;
use App\Models\Part;
use App\Queries\Parts\PartIndexQuery;
use App\Support\SizeRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Part::class);

        $request->validate([
            'compatible_with_asset_id' => ['nullable', 'integer', 'exists:assets,id'],
        ]);

        $results = app(PartIndexQuery::class)->build($request);

        return PartResource::collection($results)->toResponse($request);
    }

    public function show(Request $request, Part $part): JsonResponse
    {
        Gate::authorize('view', $part);

        return (new PartResource($part->load('maintenanceCategory')))->toResponse($request);
    }

    public function update(Request $request, Part $part, UpdatePart $action): JsonResponse
    {
        Gate::authorize('update', $part);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'available_quantity' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999999.999', 'decimal:0,3'],
            'maintenance_category_id' => ['sometimes', 'nullable', 'integer', 'exists:maintenance_categories,id'],
            'size_inches' => ['sometimes', 'nullable', new SizeRule],
            'description' => ['nullable', 'string'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $fieldUpdates = array_intersect_key(
            $validated,
            array_flip([
                'name',
                'available_quantity',
                'maintenance_category_id',
                'size_inches',
                'description',
                'unit_of_measure',
                'is_active',
            ])
        );

        $part = $action->execute($part, $fieldUpdates);

        return (new PartResource($part->fresh()->load('maintenanceCategory')))->toResponse($request);
    }
}
