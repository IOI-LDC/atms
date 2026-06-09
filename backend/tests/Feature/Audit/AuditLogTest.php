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
        $this->actingAs($admin)->deleteJson("/api/admin/audit-logs/{$log->id}")->assertStatus(404);
    }
}
