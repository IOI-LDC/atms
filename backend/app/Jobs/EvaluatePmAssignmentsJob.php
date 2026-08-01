<?php

namespace App\Jobs;

use App\Models\AssetPmAssignment;
use App\Services\Pm\PmEvaluationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates one chunk of PM assignments, fanned out by EvaluatePmRulesJob.
 *
 * The chunk is carried as ids rather than models so the payload stays small and
 * every row is re-read at execution time — by then a rule may have been
 * deactivated or an MR already raised, and the scope filters are re-applied
 * here for exactly that reason.
 */
class EvaluatePmAssignmentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 300;

    /**
     * @param  array<int, int>  $assignmentIds
     */
    public function __construct(
        public readonly array $assignmentIds,
        public readonly int $triggeredByUserId,
    ) {}

    public function handle(PmEvaluationRunner $runner): void
    {
        $assignments = AssetPmAssignment::query()
            ->whereIn('id', $this->assignmentIds)
            ->evaluable()
            ->with('pmRule')
            ->get();

        $result = $runner->run($assignments, $this->triggeredByUserId);

        Log::info("PM evaluation chunk: {$result['generated']} requests generated from {$result['evaluated']} assignments.");
    }
}
