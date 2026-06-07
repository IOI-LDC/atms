<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
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

    public function deactivate(User $user, DeactivateUser $action): JsonResponse
    {
        Gate::authorize('manage', User::class);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Cannot deactivate yourself.'], 422);
        }

        $user = $action->execute($user);

        return response()->json(['message' => 'User deactivated.', 'data' => $user]);
    }

    public function reactivate(User $user, ReactivateUser $action): JsonResponse
    {
        Gate::authorize('manage', User::class);

        $user = $action->execute($user);

        return response()->json(['message' => 'User reactivated.', 'data' => $user]);
    }
}
