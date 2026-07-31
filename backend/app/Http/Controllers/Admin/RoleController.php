<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        return response()->json(['data' => Role::all()]);
    }
}
