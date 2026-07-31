<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FaSubclassTypeCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FaSubclassTypeCodeController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', FaSubclassTypeCode::class);

        return response()->json(['data' => FaSubclassTypeCode::orderBy('fa_subclass_code')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', FaSubclassTypeCode::class);

        $validated = $request->validate([
            'fa_subclass_code' => ['required', 'string', 'max:20', 'unique:fa_subclass_type_codes,fa_subclass_code'],
            'type_code' => ['required', 'string', 'max:3'],
            'description' => ['nullable', 'string'],
            'has_no_physical_size' => ['boolean'],
        ]);

        $entry = FaSubclassTypeCode::create($validated);

        return response()->json(['data' => $entry], 201);
    }

    public function show(FaSubclassTypeCode $code): JsonResponse
    {
        Gate::authorize('view', $code);

        return response()->json(['data' => $code]);
    }

    public function update(Request $request, FaSubclassTypeCode $code): JsonResponse
    {
        Gate::authorize('update', $code);

        $validated = $request->validate([
            'type_code' => ['nullable', 'string', 'max:3'],
            'description' => ['nullable', 'string'],
            'has_no_physical_size' => ['boolean'],
        ]);

        $code->update($validated);

        return response()->json(['data' => $code->fresh()]);
    }

    public function destroy(FaSubclassTypeCode $code): JsonResponse
    {
        Gate::authorize('delete', $code);

        $code->delete();

        return response()->json(null, 204);
    }
}
