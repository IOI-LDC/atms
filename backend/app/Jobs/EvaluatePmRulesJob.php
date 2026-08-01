<?php

namespace App\Jobs;

use App\Models\AssetPmAssignment;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Nightly PM evaluation: finds every assignment worth considering and fans the
 * work out to EvaluatePmAssignmentsJob, one child per chunk.
 *
 * It deliberately does no evaluation itself. `maintenance_level` is an L1–L4
 * scheme, so roughly four rules per asset is the designed shape — a few hundred
 * assets is already thousands of assignments, and a single run that both walked
 * and evaluated them could not fit in one timeout. Chunking keeps this job's
 * own work proportional to a cursor over ids, and each child gets a full
 * timeout for a bounded slice.
 */
class EvaluatePmRulesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 300;

    public int $uniqueFor = 300;

    /**
     * Sized so a chunk's readings and suppressions stay comfortably in memory
     * while keeping the number of child jobs small.
     */
    private const CHUNK_SIZE = 200;

    public function handle(): void
    {
        $systemUser = User::where('email', 'system@atms.internal')->first();
        $triggeredByUserId = $systemUser?->id ?? throw new \RuntimeException('System user not found. Run db:seed.');

        $chunks = 0;
        $dispatched = 0;

        AssetPmAssignment::query()
            ->evaluable()
            ->select('asset_pm_assignments.id')
            ->chunkById(self::CHUNK_SIZE, function ($assignments) use ($triggeredByUserId, &$chunks, &$dispatched) {
                $chunks++;
                $dispatched += $assignments->count();

                EvaluatePmAssignmentsJob::dispatch(
                    $assignments->pluck('id')->all(),
                    $triggeredByUserId,
                );
            }, 'asset_pm_assignments.id', 'id');

        Log::info("PM evaluation dispatched: {$dispatched} assignments across {$chunks} chunk(s).");
    }
}
