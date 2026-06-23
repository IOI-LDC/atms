<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EvaluatePmRulesJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobConfigTest extends TestCase
{
    public static function jobConfigProvider(): array
    {
        return [
            'EvaluatePmRulesJob' => [EvaluatePmRulesJob::class, 3, [60, 300, 900], 300],
        ];
    }

    #[DataProvider('jobConfigProvider')]
    public function test_job_has_correct_retry_config(string $jobClass, int $tries, array $backoff, int $timeout): void
    {
        $job = new $jobClass;

        $this->assertEquals($tries, $job->tries);
        $this->assertEquals($backoff, $job->backoff);
        $this->assertEquals($timeout, $job->timeout);
    }

    public function test_evaluate_pm_rules_has_unique_for(): void
    {
        $job = new EvaluatePmRulesJob;

        $this->assertEquals(300, $job->uniqueFor);
    }

    public function test_evaluate_pm_rules_job_is_unique(): void
    {
        $this->assertContains(ShouldBeUnique::class, class_implements(EvaluatePmRulesJob::class));
    }

    public function test_evaluate_pm_dispatches(): void
    {
        Queue::fake();

        EvaluatePmRulesJob::dispatch();

        Queue::assertPushed(EvaluatePmRulesJob::class, 1);
    }
}
