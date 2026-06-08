<?php

namespace Tests\Feature\Jobs;

use App\Actions\Erp\SyncAssets;
use App\Actions\Erp\SyncParts;
use App\Jobs\EvaluatePmRulesJob;
use App\Jobs\SyncErpAssetsJob;
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
            'SyncErpAssetsJob' => [SyncErpAssetsJob::class, 3, [60, 300, 900], 3600],
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

    public function test_erp_sync_jobs_are_unique(): void
    {
        $this->assertContains(ShouldBeUnique::class, class_implements(SyncErpAssetsJob::class));
        $this->assertContains(ShouldBeUnique::class, class_implements(SyncErpPartsJob::class));
    }

    public function test_evaluate_pm_rules_job_is_unique(): void
    {
        $this->assertContains(ShouldBeUnique::class, class_implements(EvaluatePmRulesJob::class));
    }

    public function test_sync_erp_assets_dispatches(): void
    {
        Queue::fake();

        SyncErpAssetsJob::dispatch(1);

        Queue::assertPushed(SyncErpAssetsJob::class, 1);
    }

    public function test_sync_erp_assets_calls_action(): void
    {
        $this->mock(SyncAssets::class, function ($mock) {
            $mock->shouldReceive('execute')->once()->with(1);
        });

        (new SyncErpAssetsJob(1))->handle(app(SyncAssets::class));
    }

    public function test_sync_erp_assets_dispatches_without_user(): void
    {
        Queue::fake();

        SyncErpAssetsJob::dispatch();

        Queue::assertPushed(SyncErpAssetsJob::class, function ($job) {
            return $job->triggeredByUserId === null;
        });
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
