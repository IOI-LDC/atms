<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MaintenanceCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', MaintenanceCategory::class);

        return response()->json(['data' => MaintenanceCategory::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', MaintenanceCategory::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $code = MaintenanceCategory::codeFor($validated['name']);

        if ($code === '' || mb_strlen($code) > 50) {
            throw ValidationException::withMessages([
                'name' => 'The category name must produce a code of 50 characters or fewer.',
            ]);
        }

        if (MaintenanceCategory::where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A maintenance category with this name already exists.',
            ]);
        }

        $category = MaintenanceCategory::create([
            'code' => $code,
            'name' => $validated['name'],
            'is_active' => true,
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function update(Request $request, MaintenanceCategory $category): JsonResponse
    {
        Gate::authorize('update', $category);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // The code is the stable identifier (route key) and is never changed.
        $category->update($validated);

        return response()->json(['data' => $category->fresh()]);
    }
}
