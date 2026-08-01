<?php

namespace App\Jobs;

use App\Actions\Pm\ReconcilePmCategoryAssignments;
use App\Models\Asset;
use App\Models\PmRule;
use App\Support\Jobs\OverlapKeys;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Expands a PM rule's category links into per-asset assignments, off the
 * request.
 *
 * Queued because one click ("cover the Mud Motors category") can mean hundreds
 * of assignment rows, each with its own transaction and audit trail — far too
 * much to hold an HTTP response open for.
 *
 * Overlap is prevented per scope: two edits to the same rule in quick
 * succession must not interleave, or they can race on the same assignment rows.
 * Different rules reconcile in parallel quite happily.
 */
class ReconcilePmCategoryAssignmentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 300;

    private function __construct(
        public readonly ?int $pmRuleId,
        public readonly ?int $assetId,
    ) {}

    public static function forRule(int $pmRuleId): self
    {
        return new self($pmRuleId, null);
    }

    public static function forAsset(int $assetId): self
    {
        return new self(null, $assetId);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $scope = $this->pmRuleId !== null ? "rule-{$this->pmRuleId}" : "asset-{$this->assetId}";

        return [(new WithoutOverlapping(OverlapKeys::PM_CATEGORY_RECONCILE.":{$scope}"))->expireAfter(300)];
    }

    public function handle(ReconcilePmCategoryAssignments $action): void
    {
        if ($this->pmRuleId !== null) {
            $rule = PmRule::find($this->pmRuleId);

            if ($rule === null) {
                return;
            }

            $outcome = $action->forRule($rule);
            Log::info("PM category reconcile (rule {$this->pmRuleId}): ".json_encode($outcome));

            return;
        }

        $asset = Asset::find($this->assetId);

        if ($asset === null) {
            return;
        }

        $outcome = $action->forAsset($asset);
        Log::info("PM category reconcile (asset {$this->assetId}): ".json_encode($outcome));
    }
}
