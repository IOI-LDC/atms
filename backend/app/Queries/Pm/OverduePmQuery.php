<?php

namespace App\Queries\Pm;

use App\Models\PmRule;
use App\Services\Pm\PmDueCalculator;
use Illuminate\Support\Collection;

class OverduePmQuery
{
    public function __construct(private PmDueCalculator $calculator) {}

    public function execute(int $limit = 5): Collection
    {
        return PmRule::where('is_active', true)
            ->with('asset')
            ->get()
            ->filter(fn ($rule) => $this->calculator->isDue($rule))
            ->take($limit)
            ->values();
    }
}
