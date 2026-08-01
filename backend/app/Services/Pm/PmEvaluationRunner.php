<?php

namespace App\Services\Pm;

use App\Actions\Pm\EvaluatePmRule;
use App\Models\AssetPmAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates a set of PM assignments as one batch.
 *
 * Two things make this cheap where the previous per-assignment loop was not:
 *
 * 1. Readings and suppressions are loaded once for the whole set
 *    (`PmEvaluationBatch`) instead of per assignment.
 * 2. Due-ness is checked **before** opening a transaction. The old loop took a
 *    `lockForUpdate` on every assignment just to discover that nothing was due,
 *    which is the common case on any given day — a rule with a 30-day interval
 *    is not due on 29 of them. The lock still happens for assignments that look
 *    due, and `EvaluatePmRule` re-checks under it, so the guarantee is unchanged.
 */
final class PmEvaluationRunner
{
    public function __construct(
        private PmDueCalculator $calculator,
        private EvaluatePmRule $action,
    ) {}

    /**
     * @param  Collection<int, AssetPmAssignment>  $assignments
     * @return array{evaluated: int, generated: int}
     */
    public function run(Collection $assignments, int $triggeredByUserId): array
    {
        if ($assignments->isEmpty()) {
            return ['evaluated' => 0, 'generated' => 0];
        }

        $batch = PmEvaluationBatch::for($assignments);
        $generated = 0;

        foreach ($assignments as $assignment) {
            if (! $this->calculator->isDue($assignment, $batch->readings, $batch->suppressions)) {
                continue;
            }

            try {
                if ($this->action->execute($assignment, $triggeredByUserId, $batch) !== null) {
                    $generated++;
                }
            } catch (\DomainException $e) {
                Log::info("PM evaluation skipped assignment {$assignment->id}: {$e->getMessage()}");
            }
        }

        return ['evaluated' => $assignments->count(), 'generated' => $generated];
    }
}
