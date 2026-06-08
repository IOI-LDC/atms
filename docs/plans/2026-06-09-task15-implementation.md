# Task 15: Queue, Scheduler, and Failure Hardening — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Harden queue jobs and scheduled tasks with bounded retries, exponential backoff, explicit overlap keys, onOneServer scheduling, and consistent job configuration.

**Architecture:** Add OverlapKeys constants, update job retry/timeout configs, migrate EvaluatePmRulesJob to new Queueable trait, update schedule with explicit times and onOneServer, add comprehensive test coverage.

**Tech Stack:** Laravel 13, PostgreSQL queue driver, Laravel scheduler

---

### Task 1: Create OverlapKeys and update schedule

**Files:**
- Create: `backend/app/Support/Jobs/OverlapKeys.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/Jobs/ScheduleTest.php`

**Step 1: Write failing test**

Create `backend/tests/Feature/Jobs/ScheduleTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EvaluatePmRulesJob;
use App\Jobs\SyncErpAssetsJob;
use App\Jobs\SyncErpPartsJob;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_erp_asset_sync_is_scheduled_weekly_monday_at_02_00(): void
    {
        $events = Schedule::dueEvents($this->app);
        $found = collect($events)->first(fn ($event) =>
            str_contains($event->description, SyncErpAssetsJob::class)
        );

        $this->assertNotNull($found, 'SyncErpAssetsJob not found in schedule');
        $this->assertStringContainsString('weekly', $found->expression);
        $this->assertEquals('Africa/Tripoli', $found->timezone?->getName());
    }

    public function test_erp_parts_sync_is_scheduled_weekly(): void
    {
        $events = Schedule::dueEvents($this->app);
        $found = collect($events)->first(fn ($event) =>
            str_contains($event->description, SyncErpPartsJob::class)
        );

        $this->assertNotNull($found, 'SyncErpPartsJob not found in schedule');
        $this->assertStringContainsString('weekly', $found->expression);
    }

    public function test_pm_evaluation_is_scheduled_daily(): void
    {
        $events = Schedule::dueEvents($this->app);
        $found = collect($events)->first(fn ($event) =>
            str_contains($event->description, EvaluatePmRulesJob::class)
        );

        $this->assertNotNull($found, 'EvaluatePmRulesJob not found in schedule');
        $this->assertStringContainsString('* * *', $found->expression);
    }
}
```

**Step 2: Run test to verify it passes with current schedule**

Run: `docker compose run --rm api php artisan test tests/Feature/Jobs/ScheduleTest`

**Step 3: Create OverlapKeys**

Create `backend/app/Support/Jobs/OverlapKeys.php`:

```php
<?php

namespace App\Support\Jobs;

class OverlapKeys
{
    public const ERP_ASSET_SYNC = 'erp-asset-sync';

    public const ERP_PART_SYNC = 'erp-part-sync';

    public const PM_EVALUATION = 'pm-evaluation';
}
```

**Step 4: Update routes/console.php**

Replace the three schedule lines with:

```php
<?php

use App\Jobs\EvaluatePmRulesJob;
use App\Jobs\SyncErpAssetsJob;
use App\Jobs\SyncErpPartsJob;
use App\Support\Jobs\OverlapKeys;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncErpAssetsJob)
    ->weekly()->mondays()->at('02:00')
    ->timezone('Africa/Tripoli')
    ->withoutOverlapping(OverlapKeys::ERP_ASSET_SYNC)
    ->onOneServer();

Schedule::job(new SyncErpPartsJob)
    ->weekly()->mondays()->at('03:00')
    ->timezone('Africa/Tripoli')
    ->withoutOverlapping(OverlapKeys::ERP_PART_SYNC)
    ->onOneServer();

Schedule::job(new EvaluatePmRulesJob)
    ->daily()->at('06:00')
    ->timezone('Africa/Tripoli')
    ->withoutOverlapping(OverlapKeys::PM_EVALUATION)
    ->onOneServer();
```

**Step 5: Run tests**

Run: `docker compose run --rm api php artisan test tests/Feature/Jobs/ScheduleTest`
Run: `docker compose run --rm api php artisan test`

**Step 6: Commit**

```bash
git add backend
git commit -m "feat: add OverlapKeys constants and harden schedule with explicit times and onOneServer"
```

---

### Task 2: Harden job configurations (tries, backoff, timeout)

**Files:**
- Modify: `backend/app/Jobs/SyncErpAssetsJob.php`
- Modify: `backend/app/Jobs/SyncErpPartsJob.php`
- Modify: `backend/app/Jobs/EvaluatePmRulesJob.php`
- Modify: `backend/app/Notifications/UserActivationNotification.php`
- Modify: `backend/app/Notifications/PasswordResetNotification.php`
- Test: `backend/tests/Feature/Jobs/JobConfigTest.php`

**Step 1: Write failing test**

Create `backend/tests/Feature/Jobs/JobConfigTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EvaluatePmRulesJob;
use App\Jobs\SyncErpAssetsJob;
use App\Jobs\SyncErpPartsJob;
use App\Notifications\PasswordResetNotification;
use App\Notifications\UserActivationNotification;
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
        $job = new $jobClass();

        $this->assertEquals($tries, $job->tries);
        $this->assertEquals($backoff, $job->backoff);
        $this->assertEquals($timeout, $job->timeout);
    }

    public function test_erp_sync_jobs_are_unique(): void
    {
        $this->assertContains(\Illuminate\Contracts\Queue\ShouldBeUnique::class, class_implements(SyncErpAssetsJob::class));
        $this->assertContains(\Illuminate\Contracts\Queue\ShouldBeUnique::class, class_implements(SyncErpPartsJob::class));
    }

    public function test_evaluate_pm_rules_job_is_unique(): void
    {
        $this->assertContains(\Illuminate\Contracts\Queue\ShouldBeUnique::class, class_implements(EvaluatePmRulesJob::class));
    }

    public function test_notifications_have_retry_config(): void
    {
        $activation = new \ReflectionClass(UserActivationNotification::class);
        $reset = new \ReflectionClass(PasswordResetNotification::class);

        $this->assertEquals(3, $activation->getDefaultProperties()['tries'] ?? null);
        $this->assertEquals([30, 120, 300], $activation->getDefaultProperties()['backoff'] ?? null);

        $this->assertEquals(3, $reset->getDefaultProperties()['tries'] ?? null);
        $this->assertEquals([30, 120, 300], $reset->getDefaultProperties()['backoff'] ?? null);
    }
}
```

**Step 2: Run test to verify failure**

Run: `docker compose run --rm api php artisan test tests/Feature/Jobs/JobConfigTest`
Expected: FAIL (jobs don't have tries/backoff yet, EvaluatePmRulesJob uses old traits)

**Step 3: Update SyncErpAssetsJob**

Replace `backend/app/Jobs/SyncErpAssetsJob.php`:

```php
<?php

namespace App\Jobs;

use App\Actions\Erp\SyncAssets;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncErpAssetsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(
        public ?int $triggeredByUserId = null
    ) {}

    public function handle(SyncAssets $action): void
    {
        $action->execute($this->triggeredByUserId);
    }
}
```

**Step 4: Update SyncErpPartsJob**

Replace `backend/app/Jobs/SyncErpPartsJob.php`:

```php
<?php

namespace App\Jobs;

use App\Actions\Erp\SyncParts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncErpPartsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(
        public ?int $triggeredByUserId = null
    ) {}

    public function handle(SyncParts $action): void
    {
        $action->execute($this->triggeredByUserId);
    }
}
```

**Step 5: Refactor EvaluatePmRulesJob**

Replace `backend/app/Jobs/EvaluatePmRulesJob.php`:

```php
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
```

**Step 6: Update notifications**

Add to `UserActivationNotification.php` after `use Queueable;`:

```php
public int $tries = 3;

public array $backoff = [30, 120, 300];
```

Add to `PasswordResetNotification.php` after `use Queueable;`:

```php
public int $tries = 3;

public array $backoff = [30, 120, 300];
```

**Step 7: Run tests**

Run: `docker compose run --rm api php artisan test tests/Feature/Jobs/JobConfigTest`
Run: `docker compose run --rm api php artisan test`

**Step 8: Commit**

```bash
git add backend
git commit -m "feat: add bounded retries with exponential backoff to all jobs and notifications"
```

---

### Task 3: Add job behavior tests

**Files:**
- Test: `backend/tests/Feature/Jobs/SyncErpAssetsJobTest.php`
- Test: `backend/tests/Feature/Jobs/SyncErpPartsJobTest.php`
- Test: `backend/tests/Feature/Jobs/EvaluatePmRulesJobTest.php`
- Test: `backend/tests/Feature/Jobs/OverlapPreventionTest.php`

**Step 1: Write SyncErpAssetsJobTest**

Create `backend/tests/Feature/Jobs/SyncErpAssetsJobTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Actions\Erp\SyncAssets;
use App\Jobs\SyncErpAssetsJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncErpAssetsJobTest extends TestCase
{
    public function test_job_dispatches_successfully(): void
    {
        Queue::fake();

        SyncErpAssetsJob::dispatch(1);

        Queue::assertPushed(SyncErpAssetsJob::class, 1);
    }

    public function test_job_calls_sync_assets_action(): void
    {
        $this->mock(SyncAssets::class, function ($mock) {
            $mock->shouldReceive('execute')->once()->with(1);
        });

        (new SyncErpAssetsJob(1))->handle(app(SyncAssets::class));
    }

    public function test_job_dispatches_without_user_id(): void
    {
        Queue::fake();

        SyncErpAssetsJob::dispatch();

        Queue::assertPushed(SyncErpAssetsJob::class, function ($job) {
            return $job->triggeredByUserId === null;
        });
    }
}
```

**Step 2: Write SyncErpPartsJobTest**

Create `backend/tests/Feature/Jobs/SyncErpPartsJobTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Actions\Erp\SyncParts;
use App\Jobs\SyncErpPartsJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncErpPartsJobTest extends TestCase
{
    public function test_job_dispatches_successfully(): void
    {
        Queue::fake();

        SyncErpPartsJob::dispatch(1);

        Queue::assertPushed(SyncErpPartsJob::class, 1);
    }

    public function test_job_calls_sync_parts_action(): void
    {
        $this->mock(SyncParts::class, function ($mock) {
            $mock->shouldReceive('execute')->once()->with(1);
        });

        (new SyncErpPartsJob(1))->handle(app(SyncParts::class));
    }
}
```

**Step 3: Write EvaluatePmRulesJobTest**

Create `backend/tests/Feature/Jobs/EvaluatePmRulesJobTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Actions\Pm\EvaluatePmRule;
use App\Jobs\EvaluatePmRulesJob;
use App\Models\Asset;
use App\Models\Location;
use App\Models\PmRule;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EvaluatePmRulesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $role = \App\Models\Role::first();
        User::factory()->create([
            'email' => 'system@atms.internal',
            'role_id' => $role->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
    }

    public function test_job_dispatches_successfully(): void
    {
        Queue::fake();

        EvaluatePmRulesJob::dispatch();

        Queue::assertPushed(EvaluatePmRulesJob::class, 1);
    }

    public function test_job_evaluates_active_rules(): void
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-001', 'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
        $admin = User::first();
        PmRule::create([
            'asset_id' => $asset->id, 'name' => 'Rule 1', 'trigger_type' => 'date',
            'interval_days' => 30, 'is_active' => true, 'created_by' => $admin->id,
        ]);

        $this->mock(EvaluatePmRule::class, function ($mock) use ($asset) {
            $mock->shouldReceive('execute')->once()->andReturn(null);
        });

        (new EvaluatePmRulesJob())->handle(app(EvaluatePmRule::class));
    }

    public function test_job_skips_inactive_rules(): void
    {
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-002', 'erp_asset_code' => 'A-002', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);
        $admin = User::first();
        PmRule::create([
            'asset_id' => $asset->id, 'name' => 'Rule 2', 'trigger_type' => 'date',
            'interval_days' => 30, 'is_active' => false, 'created_by' => $admin->id,
        ]);

        $this->mock(EvaluatePmRule::class, function ($mock) {
            $mock->shouldNotReceive('execute');
        });

        (new EvaluatePmRulesJob())->handle(app(EvaluatePmRule::class));
    }

    public function test_job_throws_when_system_user_missing(): void
    {
        User::where('email', 'system@atms.internal')->delete();

        $this->expectException(\RuntimeException::class);

        (new EvaluatePmRulesJob())->handle(app(EvaluatePmRule::class));
    }
}
```

**Step 4: Write OverlapPreventionTest**

Create `backend/tests/Feature/Jobs/OverlapPreventionTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Jobs\EvaluatePmRulesJob;
use App\Jobs\SyncErpAssetsJob;
use App\Jobs\SyncErpPartsJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OverlapPreventionTest extends TestCase
{
    public static function uniqueJobProvider(): array
    {
        return [
            'SyncErpAssetsJob' => [SyncErpAssetsJob::class],
            'SyncErpPartsJob' => [SyncErpPartsJob::class],
            'EvaluatePmRulesJob' => [EvaluatePmRulesJob::class],
        ];
    }

    #[DataProvider('uniqueJobProvider')]
    public function test_job_implements_should_be_unique(string $jobClass): void
    {
        $this->assertContains(
            \Illuminate\Contracts\Queue\ShouldBeUnique::class,
            class_implements($jobClass),
            "{$jobClass} should implement ShouldBeUnique"
        );
    }

    public function test_erp_sync_jobs_have_unique_for_configured(): void
    {
        $assetsJob = new SyncErpAssetsJob();
        $partsJob = new SyncErpPartsJob();

        $this->assertEquals(3600, $assetsJob->uniqueFor);
        $this->assertEquals(3600, $partsJob->uniqueFor);
    }
}
```

**Step 5: Run all job tests**

Run: `docker compose run --rm api php artisan test tests/Feature/Jobs`
Expected: All PASS

**Step 6: Run full regression**

Run: `docker compose run --rm api php artisan test`

**Step 7: Commit**

```bash
git add backend
git commit -m "test: add job behavior, overlap prevention, and schedule verification tests"
```

---

### Task 4: Run full regression and lint

**Step 1: Run full test suite**

Run: `docker compose run --rm api php artisan test`
Expected: All PASS

**Step 2: Run linting**

Run: `docker compose run --rm api ./vendor/bin/pint --test`

**Step 3: Verify schedule is registered**

Run: `docker compose run --rm api php artisan schedule:list`
Expected: Shows 3 scheduled jobs with correct frequencies

**Step 4: Final commit if any cleanup needed**

```bash
git add backend
git commit -m "style: fix code style for Task 15"
```
