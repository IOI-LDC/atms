<?php

namespace App\Http\Controllers;

use App\Http\Resources\PartResource;
use App\Models\Part;
use App\Queries\Parts\PartIndexQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $results = app(PartIndexQuery::class)->build($request);

        return PartResource::collection($results)->toResponse($request);
    }

    public function show(Request $request, Part $part): JsonResponse
    {
        return (new PartResource($part))->toResponse($request);
    }
}
