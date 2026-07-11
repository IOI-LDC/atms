<?php

namespace Tests\Feature\Audit;

use App\Enums\RoleCode;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function createAdmin(): User
    {
        $role = Role::where('code', RoleCode::ADMINISTRATOR)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createManager(): User
    {
        $role = Role::where('code', RoleCode::MAINTENANCE_MANAGER)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    public function test_audit_logger_redacts_sensitive_keys()
    {
        $logger = new AuditLogger;

        $log = $logger->log('test.event', null,
            ['safe' => 'data', 'password' => 'secret123', 'nested' => ['API_KEY' => 'key123']],
            ['safe' => 'data2', 'token' => 'token123']
        );

        $this->assertEquals('data', $log->before_state['safe']);
        $this->assertEquals('[REDACTED]', $log->before_state['password']);
        $this->assertEquals('[REDACTED]', $log->before_state['nested']['API_KEY']);

        $this->assertEquals('data2', $log->after_state['safe']);
        $this->assertEquals('[REDACTED]', $log->after_state['token']);
    }

    public function test_audit_logger_handles_uploaded_files_and_objects()
    {
        $logger = new AuditLogger;

        $file = UploadedFile::fake()->create('document.pdf', 100);
        $obj = (object) ['key' => 'value'];

        $log = $logger->log('file.upload', null, [], ['file' => $file, 'obj' => $obj]);

        $this->assertEquals('[FILE UPLOAD]', $log->after_state['file']);
        $this->assertEquals('[OBJECT]', $log->after_state['obj']);
    }

    public function test_administrator_can_view_audit_logs()
    {
        $admin = $this->createAdmin();
        $logger = new AuditLogger;
        $logger->log('test.admin.event');

        $response = $this->actingAs($admin)->getJson('/api/admin/audit-logs');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.event', 'test.admin.event');
    }

    public function test_non_administrator_cannot_view_audit_logs()
    {
        $manager = $this->createManager();
        $logger = new AuditLogger;
        $logger->log('test.manager.event');

        $response = $this->actingAs($manager)->getJson('/api/admin/audit-logs');

        $response->assertStatus(403);
    }

    public function test_no_mutation_routes_exist_for_audit_logs()
    {
        $admin = $this->createAdmin();

        $log = AuditLog::forceCreate([
            'event' => 'test',
        ]);

        // Attempting to hit standard mutation routes that we haven't defined
        $this->actingAs($admin)->postJson('/api/admin/audit-logs', [])->assertStatus(405);
        $this->actingAs($admin)->putJson("/api/admin/audit-logs/{$log->id}", [])->assertStatus(404);
        $this->actingAs($admin)->deleteJson("/api/admin/audit-logs/{$log->id}", [])->assertStatus(404);
    }

    public function test_per_page_is_respected_and_capped()
    {
        $admin = $this->createAdmin();
        $logger = new AuditLogger;

        // Seed 6 rows; ask for 5 — only 5 should return with a next_cursor.
        for ($i = 0; $i < 6; $i++) {
            $logger->log('test.per_page');
        }

        $response = $this->actingAs($admin)->getJson('/api/admin/audit-logs?per_page=5');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.next_cursor', function ($cursor) {
                return $cursor !== null && $cursor !== '';
            });

        // An absurd per_page is capped at 500; with 6 rows total, all 6 return and
        // there is no next_cursor.
        $all = $this->actingAs($admin)->getJson('/api/admin/audit-logs?per_page=99999');

        $all->assertStatus(200)
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_event_filter_supports_partial_match()
    {
        $admin = $this->createAdmin();
        $logger = new AuditLogger;
        $logger->log('work_order.closed');
        $logger->log('work_order.assigned');
        $logger->log('auth.login');

        // Partial match: ?event=work_order returns both work_order.* events, not auth.login.
        $response = $this->actingAs($admin)->getJson('/api/admin/audit-logs?event=work_order');

        $events = collect($response->json('data'))->pluck('event')->sort()->values();

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
        $this->assertEquals(['work_order.assigned', 'work_order.closed'], $events->all());

        // A full event name is a substring of itself, so exact-match still works.
        $this->actingAs($admin)
            ->getJson('/api/admin/audit-logs?event=auth.login')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'auth.login');
    }

    public function test_from_and_to_date_filters_scoped_correctly()
    {
        $admin = $this->createAdmin();

        // Three logs at distinct, controlled timestamps. guard=[] lets us set
        // created_at directly.
        $t0 = '2026-01-10 00:00:00';
        $t1 = '2026-02-10 00:00:00';
        $t2 = '2026-03-10 00:00:00';

        AuditLog::forceCreate(['event' => 'day.zero', 'created_at' => $t0]);
        AuditLog::forceCreate(['event' => 'day.one', 'created_at' => $t1]);
        AuditLog::forceCreate(['event' => 'day.two', 'created_at' => $t2]);

        // ?from excludes t0.
        $from = $this->actingAs($admin)->getJson('/api/admin/audit-logs?from='.$t1);
        $from->assertStatus(200)->assertJsonCount(2, 'data');

        // ?to excludes t2.
        $to = $this->actingAs($admin)->getJson('/api/admin/audit-logs?to='.$t1);
        $to->assertStatus(200)->assertJsonCount(2, 'data');

        // ?from&to returns only the middle row.
        $window = $this->actingAs($admin)->getJson('/api/admin/audit-logs?from='.$t1.'&to='.$t1);
        $window->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'day.one');
    }

    public function test_filters_combine_with_cursor_pagination()
    {
        $admin = $this->createAdmin();
        $logger = new AuditLogger;

        // 3 matching + 2 non-matching events; ask for 2/page with an event filter.
        for ($i = 0; $i < 3; $i++) {
            $logger->log('work_order.started');
        }
        $logger->log('auth.logout');
        $logger->log('user.updated');

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/audit-logs?event=work_order&per_page=2');

        // Page 1: 2 of the 3 matching rows, with a cursor to the rest. The 2
        // non-matching rows never appear.
        $events = collect($response->json('data'))->pluck('event');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.next_cursor', function ($cursor) {
                return $cursor !== null && $cursor !== '';
            });
        $this->assertTrue($events->every(fn ($event) => $event === 'work_order.started'));
    }
}
