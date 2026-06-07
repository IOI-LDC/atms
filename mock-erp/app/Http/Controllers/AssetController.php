<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'updated_since' => ['nullable', 'date'],
        ]);

        $query = Asset::query();

        if ($request->has('updated_since')) {
            $query->where('updated_at', '>=', $request->input('updated_since'));
        }

        $limit = $request->input('limit', 15);
        if ($limit > 100) {
            $limit = 100;
        }

        $assets = $query->orderBy('id')->cursorPaginate($limit);

        return response()->json($assets);
    }
}
