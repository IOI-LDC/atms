<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\ImportEmployees;
use App\Actions\Employees\ProvisionEmployeeUser;
use App\Contracts\Employees\EmployeeDirectorySource;
use App\Data\Employees\ExternalEmployeeData;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class EmployeeController extends Controller
{
    public function __construct(
        private EmployeeDirectorySource $directory,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Employee::class);

        $employees = $this->directory->getEmployees();

        $employees = $this->applyEmpIdFilter($employees, $request);
        $employees = $this->applySearch($employees, $request);
        $employees = $this->applySort($employees, $request);

        $perPage = min((int) $request->input('per_page', 25), 100);
        $page = (int) $request->input('page', 1);

        $paginated = new LengthAwarePaginator(
            $employees->forPage($page, $perPage)->values(),
            $employees->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $models = $paginated->getCollection()->map(
            fn (ExternalEmployeeData $dto) => $this->hydrateModel($dto)
        );

        $paginated->setCollection($models);

        return EmployeeResource::collection($paginated)->toResponse($request);
    }

    public function import(ImportEmployees $action): JsonResponse
    {
        Gate::authorize('manage', Employee::class);

        $count = $action->execute();

        return response()->json(['message' => "Imported {$count} employees."]);
    }

    public function provisionUser(Request $request, ProvisionEmployeeUser $action): JsonResponse
    {
        Gate::authorize('manage', Employee::class);

        $validated = $request->validate([
            'emp_id' => ['required', 'string'],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $external = $this->directory->findOrFail($validated['emp_id']);
        $role = Role::findOrFail($validated['role_id']);

        $employee = $this->ensureEmployeeRecord($external);

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

    private function ensureEmployeeRecord(ExternalEmployeeData $external): Employee
    {
        $employee = Employee::where('sharepoint_item_id', $external->sharepointItemId)->first()
            ?? Employee::where('emp_id', $external->empId)->first()
            ?? new Employee;

        $employee->sharepoint_item_id = $external->sharepointItemId;
        $employee->emp_id = $external->empId;
        $employee->fill([
            'name' => $external->name,
            'email' => $external->email,
            'department' => $external->department,
            'job_title' => $external->jobTitle,
            'source_is_active' => $external->isActive,
            'source_updated_at' => $external->updatedAt,
            'source_raw_data' => $external->rawData,
            'last_synced_at' => now(),
        ])->save();

        return $employee;
    }

    private function hydrateModel(ExternalEmployeeData $dto): Employee
    {
        $employee = new Employee([
            'emp_id' => $dto->empId,
            'name' => $dto->name,
            'email' => $dto->email,
            'department' => $dto->department,
            'job_title' => $dto->jobTitle,
            'source_is_active' => $dto->isActive,
            'source_updated_at' => $dto->updatedAt,
            'last_synced_at' => null,
        ]);

        // Set the auto-increment ID to null — this is an unsaved record.
        $employee->id = null;

        return $employee;
    }

    /**
     * @param  Collection<int, ExternalEmployeeData>  $employees
     * @return Collection<int, ExternalEmployeeData>
     */
    private function applyEmpIdFilter($employees, Request $request)
    {
        // Explicit query parameter takes precedence over config default.
        if ($request->filled('emp_ids')) {
            $ids = $request->input('emp_ids');

            if (is_string($ids)) {
                $ids = array_map('trim', explode(',', $ids));
            }

            $ids = array_map('strval', (array) $ids);

            return $employees->filter(fn (ExternalEmployeeData $dto) => in_array($dto->empId, $ids, true))->values();
        }

        // Fall back to config-based whitelist.
        $visibleIds = config('employees.visible_emp_ids');

        if (is_array($visibleIds) && $visibleIds !== []) {
            $visibleIds = array_map('strval', $visibleIds);

            return $employees->filter(fn (ExternalEmployeeData $dto) => in_array($dto->empId, $visibleIds, true))->values();
        }

        return $employees;
    }

    /**
     * @param  Collection<int, ExternalEmployeeData>  $employees
     * @return Collection<int, ExternalEmployeeData>
     */
    private function applySearch($employees, Request $request)
    {
        if (! $request->filled('search')) {
            return $employees;
        }

        $search = mb_strtolower($request->input('search'));

        return $employees->filter(function (ExternalEmployeeData $dto) use ($search) {
            return str_contains(mb_strtolower($dto->name), $search)
                || str_contains(mb_strtolower($dto->empId), $search)
                || str_contains(mb_strtolower($dto->email), $search);
        })->values();
    }

    /**
     * @param  Collection<int, ExternalEmployeeData>  $employees
     * @return Collection<int, ExternalEmployeeData>
     */
    private function applySort($employees, Request $request)
    {
        $sort = $request->input('sort', 'name:asc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');

        $allowedSorts = ['name', 'emp_id'];

        if (! in_array($field, $allowedSorts, true)) {
            $field = 'name';
            $direction = 'asc';
        }

        $desc = $direction === 'desc';

        return $employees->sortBy($field, SORT_REGULAR, $desc)->values();
    }
}
