<?php

namespace App\Queries\Employees;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

class EmployeeIndexQuery
{
    protected array $allowedSorts = [
        'name' => 'name',
        'emp_id' => 'emp_id',
    ];

    public function build(Request $request): CursorPaginator
    {
        $query = Employee::query();

        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->cursorPaginate($perPage);
    }

    protected function applyFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            // LOWER() on both sides keeps matching case-insensitive on every
            // supported driver. Plain LIKE is case-sensitive on PostgreSQL;
            // ILIKE is not valid on SQLite. See ticket: case-sensitive search.
            $term = '%'.strtolower($request->input('search')).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(emp_id) LIKE ?', [$term]));
        }
    }

    protected function applySort($query, Request $request): void
    {
        $sort = $request->input('sort', 'name:asc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');

        if (isset($this->allowedSorts[$field])) {
            $query->orderBy($this->allowedSorts[$field], $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('name', 'asc');
        }
    }
}
