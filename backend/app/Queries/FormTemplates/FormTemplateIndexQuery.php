<?php

namespace App\Queries\FormTemplates;

use App\Models\FormTemplate;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

class FormTemplateIndexQuery
{
    /** @var array<string, string> */
    protected array $allowedSorts = [
        'name' => 'name',
        'created_at' => 'created_at',
        'is_active' => 'is_active',
    ];

    public function build(Request $request): CursorPaginator
    {
        $query = FormTemplate::with(['fields']);

        $this->applyFilters($query, $request);
        $this->applySort($query, $request);

        $perPage = min((int) $request->input('per_page', 25), 100);

        return $query->cursorPaginate($perPage);
    }

    protected function applyFilters($query, Request $request): void
    {
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('fa_subclass_code')) {
            $query->where('fa_subclass_code', $request->input('fa_subclass_code'));
        }
    }

    protected function applySort($query, Request $request): void
    {
        $sort = $request->input('sort', 'created_at:desc');
        [$field, $direction] = array_pad(explode(':', $sort, 2), 2, 'desc');

        if (isset($this->allowedSorts[$field])) {
            $query->orderBy($this->allowedSorts[$field], $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('created_at');
        }
    }
}
