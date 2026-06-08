<?php

namespace App\Jobs;

use App\Actions\Pm\EvaluatePmRule;
use App\Models\PmRule;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvaluatePmRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function handle(EvaluatePmRule $action): void
    {
        $systemUser = User::where('email', 'system@atms.internal')->first();
        $triggeredByUserId = $systemUser?->id ?? throw new \RuntimeException('System user not found. Run db:seed.');

        $rules = PmRule::where('is_active', true)->get();
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
