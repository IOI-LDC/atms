<?php

namespace App\Queries\PmRules;

use App\Models\PmRule;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

class PmRuleIndexQuery
{
    protected array $allowedSorts = [
        'name' => 'name',
        'created_at' => 'created_at',
        'is_active' => 'is_active',
    ];

    public function build(Request $request): CursorPaginator
    {
        $query = PmRule::with(['asset', 'usageReadingType', 'createdBy']);

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

        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->input('asset_id'));
        }

        if ($request->filled('trigger_type')) {
            $query->where('trigger_type', $request->input('trigger_type'));
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
