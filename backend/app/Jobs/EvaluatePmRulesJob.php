<?php

namespace App\Jobs;

use App\Actions\Pm\EvaluatePmRule;
use App\Models\PmRule;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class EvaluatePmRulesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function handle(EvaluatePmRule $action): void
    {
        $systemUser = User::where('email', 'system@atms.internal')->first();
        $triggeredByUserId = $systemUser?->id ?? throw new \RuntimeException('System user not found. Run db:seed.');

        $rules = PmRule::where('is_active', true)
            ->whereHas('asset', fn ($q) => $q->where('maintenance_status', 'Active'))
            ->get();
        $generated = 0;

        foreach ($rules as $rule) {
            try {
                $mr = $action->execute($rule, $triggeredByUserId);
                if ($mr !== null) {
                    $generated++;
                }
            } catch (\DomainException $e) {
                Log::info("PM evaluation skipped rule {$rule->id}: {$e->getMessage()}");
            }
        }

        Log::info("PM evaluation completed: {$generated} requests generated from {$rules->count()} rules.");
    }
}
