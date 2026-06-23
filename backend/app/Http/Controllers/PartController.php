<?php

namespace App\Http\Controllers;

use App\Http\Resources\PartResource;
use App\Models\Part;
use App\Queries\Parts\PartIndexQuery;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Part::class);

        $results = app(PartIndexQuery::class)->build($request);

        return PartResource::collection($results)->toResponse($request);
    }

    public function show(Request $request, Part $part): JsonResponse
    {
        Gate::authorize('view', $part);

        return (new PartResource($part))->toResponse($request);
    }

    public function update(Request $request, Part $part): JsonResponse
    {
        Gate::authorize('update', $part);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $fieldUpdates = array_intersect_key(
            $validated,
            array_flip(['name', 'description', 'unit_of_measure', 'category', 'is_active'])
        );

        if (! empty($fieldUpdates)) {
            $logger = app(AuditLogger::class);
            $before = $part->toArray();
            $part->update($fieldUpdates);
            $after = $part->fresh()->toArray();
            $logger->log('part.updated', $part, $before, $after);
        }

        return (new PartResource($part->fresh()))->toResponse($request);
    }
}

