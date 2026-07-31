<?php

namespace Tests\Feature\Jobs;

use App\Actions\Erp\SyncParts;
use App\Jobs\EvaluatePmRulesJob;
use App\Jobs\SyncErpPartsJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JobConfigTest extends TestCase
{
    public static function jobConfigProvider(): array
    {
        return [
            'SyncErpPartsJob' => [SyncErpPartsJob::class, 3, [60, 300, 900], 3600],
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

    public function test_sync_erp_parts_job_has_unique_for(): void
    {
        $partsJob = new SyncErpPartsJob;

        $this->assertEquals(3600, $partsJob->uniqueFor);
    }

    public function test_evaluate_pm_rules_has_unique_for(): void
    {
        $job = new EvaluatePmRulesJob;

        $this->assertEquals(300, $job->uniqueFor);
    }

    public function test_sync_erp_parts_job_is_unique(): void
    {
        $this->assertContains(ShouldBeUnique::class, class_implements(SyncErpPartsJob::class));
    }

    public function test_evaluate_pm_rules_job_is_unique(): void
    {
        $this->assertContains(ShouldBeUnique::class, class_implements(EvaluatePmRulesJob::class));
    }

    public function test_sync_erp_parts_dispatches(): void
    {
        Queue::fake();

        SyncErpPartsJob::dispatch(1);

        Queue::assertPushed(SyncErpPartsJob::class, 1);
    }

    public function test_sync_erp_parts_calls_action(): void
    {
        $this->mock(SyncParts::class, function ($mock) {
            $mock->shouldReceive('execute')->once()->with(1);
        });

        (new SyncErpPartsJob(1))->handle(app(SyncParts::class));
    }

    public function test_evaluate_pm_dispatches(): void
    {
        Queue::fake();

        EvaluatePmRulesJob::dispatch();

        Queue::assertPushed(EvaluatePmRulesJob::class, 1);
    }
}
