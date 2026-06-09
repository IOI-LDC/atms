<?php

namespace App\Http\Controllers;

use App\Http\Resources\PartResource;
use App\Models\Part;
use App\Queries\Parts\PartIndexQuery;
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
}
