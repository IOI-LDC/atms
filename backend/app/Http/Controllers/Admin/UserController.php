<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoleCode;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $users = User::with('role')->get();

        return response()->json(['data' => $users]);
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return response()->json(['data' => $user->load('role')]);
    }

    public function deactivate(User $user): JsonResponse
    {
        Gate::authorize('manage', User::class);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Cannot deactivate yourself.'], 422);
        }

        $user->update(['is_active' => false]);
        
        // Invalidate sessions
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete(); // Remove API tokens if any exist (though Sanctum is mostly SPA here)

        return response()->json(['message' => 'User deactivated.', 'data' => $user]);
    }

    public function reactivate(User $user): JsonResponse
    {
        Gate::authorize('manage', User::class);

        $user->update(['is_active' => true]);

        return response()->json(['message' => 'User reactivated.', 'data' => $user]);
    }
    
    public function roles(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);
        
        return response()->json(['data' => Role::all()]);
    }
}
