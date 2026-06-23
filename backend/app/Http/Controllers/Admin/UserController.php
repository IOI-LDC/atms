<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\AdminResetUserPassword;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function update(Request $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Cannot update your own account through this endpoint.'], 422);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email,' . $user->id],
            'role_id' => ['nullable', 'exists:roles,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $fieldUpdates = array_intersect_key(
            $validated,
            array_flip(['name', 'email', 'role_id', 'is_active'])
        );

        if (! empty($fieldUpdates)) {
            $logger = app(AuditLogger::class);
            $before = $user->toArray();
            $user->update($fieldUpdates);
            $after = $user->fresh()->toArray();
            $logger->log('user.updated', $user, $before, $after);
        }

        return response()->json(['data' => $user->fresh()->load('role')]);
    }

    public function resetPassword(Request $request, User $user, AdminResetUserPassword $action): JsonResponse
    {
        Gate::authorize('update', $user);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Cannot reset your own password via admin endpoint.'], 422);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $action->execute($user, $validated['password']);

        return response()->json(['message' => 'Password reset successful.']);
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

