<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\ImportEmployees;
use App\Actions\Employees\ProvisionEmployeeUser;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\Role;
use App\Queries\Employees\EmployeeIndexQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $results = app(EmployeeIndexQuery::class)->build($request);

        return EmployeeResource::collection($results)->toResponse($request);
    }

    public function import(ImportEmployees $action): JsonResponse
    {
        Gate::authorize('manage', Employee::class);

        $count = $action->execute();

        return response()->json(['message' => "Imported {$count} employees."]);
    }

    public function provisionUser(Request $request, Employee $employee, ProvisionEmployeeUser $action): JsonResponse
    {
        Gate::authorize('manage', Employee::class);

        $validated = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $role = Role::findOrFail($validated['role_id']);

        try {
            $user = $action->execute($employee, $role);

            return response()->json([
                'message' => 'User provisioned and activation email queued.',
                'data' => $user,
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
