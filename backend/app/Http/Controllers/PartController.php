<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Http\Resources\PartResource;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Part::query();

        if (! $user->hasRole(RoleCode::ADMINISTRATOR) && ! $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('erp_part_code', 'like', "%{$search}%"));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $results = $query->orderBy('name')->cursorPaginate($perPage);

        return PartResource::collection($results)->toResponse($request);
    }

    public function show(Request $request, Part $part): JsonResponse
    {
        return (new PartResource($part))->toResponse($request);
    }
}
