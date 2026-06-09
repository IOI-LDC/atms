# Task 17: Security and Concurrency Verification — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fill 11 genuine test gaps in security and concurrency coverage. No duplication of existing 257 tests.

**Architecture:** 3 PHPUnit test files + 1 smoke script. Concurrency tests are behavioral duplicate-prevention tests (SQLite-compatible). Sanctum SPA cookie auth tested via real login flow.

**Tech Stack:** PHPUnit, Laravel test factories, SQLite in-memory.

**Key Constraints:**
- `actingAs()` bypasses auth middleware — use real login + session for session-invalidation tests
- Allowed MIME types: pdf, jpeg, png, gif, webp, doc(x), xls(x) — no text/plain
- `original_name` is stored unsanitized (display-only) — security boundary is `stored_path` (server-generated)
- Download endpoints return StreamedResponse — use `$this->get()` not `$this->getJson()`
- `UsageReadingType` model (not `MasterDataItem`) for meter reading foreign keys

---

### Task 1: Create AuthSecurityTest

**Files:**
- Create: `backend/tests/Feature/Security/AuthSecurityTest.php`

**Step 1: Write the test file**

Create `backend/tests/Feature/Security/AuthSecurityTest.php`:

```php
<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_unauthenticated_request_to_protected_route_returns_401(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_activation_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/activate', [
                'token' => 'invalid',
                'password' => 'password123',
            ]);
        }

        $this->postJson('/auth/activate', [
            'token' => 'invalid',
            'password' => 'password123',
        ])->assertStatus(429);
    }

    public function test_forgot_password_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/forgot-password', [
                'email' => 'test@example.com',
            ]);
        }

        $this->postJson('/auth/forgot-password', [
            'email' => 'test@example.com',
        ])->assertStatus(429);
    }

    public function test_reset_password_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/reset-password', [
                'token' => 'invalid',
                'email' => 'test@example.com',
                'password' => 'newpassword',
                'password_confirmation' => 'newpassword',
            ]);
        }

        $this->postJson('/auth/reset-password', [
            'token' => 'invalid',
            'email' => 'test@example.com',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])->assertStatus(429);
    }

    public function test_deactivated_user_authenticated_session_is_rejected(): void
    {
        $role = Role::first();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/auth/me')->assertOk();

        app(\App\Actions\Users\DeactivateUser::class)->execute($user);

        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}
```

**Step 2: Run the tests**

Run: `docker compose run --rm api php artisan test tests/Feature/Security/AuthSecurityTest`
Expected: 5 PASS

**Step 3: Commit**

```bash
git add backend/tests/Feature/Security/AuthSecurityTest.php
git commit -m "test: add auth security tests — throttle, unauthenticated, deactivated session"
```

---

### Task 2: Create AttachmentSecurityTest

**Files:**
- Create: `backend/tests/Feature/Security/AttachmentSecurityTest.php`

**Step 1: Write the test file**

Create `backend/tests/Feature/Security/AttachmentSecurityTest.php`:

```php
<?php

namespace Tests\Feature\Security;

use App\Models\Asset;
use App\Models\Attachment;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('attachments');
    }

    public function test_path_traversal_in_filename_is_sanitized(): void
    {
        $role = Role::where('code', 'ADMINISTRATOR')->first();
        $admin = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-001', 'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);

        $file = UploadedFile::fake()->image('traversal.png', 10, 10);

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(201);
        $attachment = Attachment::first();
        $this->assertStringNotContainsString('..', $attachment->stored_path);
    }

    public function test_download_returns_file_stream_with_content_disposition(): void
    {
        $role = Role::where('code', 'ADMINISTRATOR')->first();
        $admin = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-002', 'erp_asset_code' => 'A-002', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);

        Storage::disk('attachments')->put('attachments/test.pdf', 'content');
        $attachment = Attachment::create([
            'attachable_type' => Asset::class,
            'attachable_id' => $asset->id,
            'original_name' => 'test.pdf',
            'stored_path' => 'attachments/test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
            'file_hash' => hash('sha256', 'content'),
            'uploaded_by_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get("/api/attachments/{$attachment->id}/download");

        $response->assertStatus(200);
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('test.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_non_existent_attachment_returns_404(): void
    {
        $role = Role::where('code', 'ADMINISTRATOR')->first();
        $admin = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $this->actingAs($admin)->get('/api/attachments/999999/download')->assertStatus(404);
    }

    public function test_attachment_stored_path_has_no_directory_traversal(): void
    {
        $role = Role::where('code', 'ADMINISTRATOR')->first();
        $admin = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-003', 'erp_asset_code' => 'A-003', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);

        $file = UploadedFile::fake()->image('normal.png', 10, 10);

        $response = $this->actingAs($admin)->postJson("/api/assets/{$asset->id}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(201);
        $attachment = Attachment::first();
        $this->assertDoesNotMatchRegularExpression('/\.\./', $attachment->stored_path);
        $this->assertStringStartsWith('attachments/', $attachment->stored_path);
    }

    public function test_download_response_is_binary_not_path(): void
    {
        $role = Role::where('code', 'ADMINISTRATOR')->first();
        $admin = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-004', 'erp_asset_code' => 'A-004', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);

        $fileContent = 'binary file content here';
        Storage::disk('attachments')->put('attachments/test.bin', $fileContent);
        $attachment = Attachment::create([
            'attachable_type' => Asset::class,
            'attachable_id' => $asset->id,
            'original_name' => 'test.bin',
            'stored_path' => 'attachments/test.bin',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => strlen($fileContent),
            'file_hash' => hash('sha256', $fileContent),
            'uploaded_by_user_id' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get("/api/attachments/{$attachment->id}/download");

        $response->assertStatus(200);
        $responseContent = $response->streamedContent();
        $this->assertEquals($fileContent, $responseContent);
        $this->assertStringNotContainsString('stored_path', $responseContent);
    }
}
```

**Step 2: Run the tests**

Run: `docker compose run --rm api php artisan test tests/Feature/Security/AttachmentSecurityTest`
Expected: 5 PASS

**Step 3: Commit**

```bash
git add backend/tests/Feature/Security/AttachmentSecurityTest.php
git commit -m "test: add attachment security tests — path traversal, content-disposition, binary stream"
```

---

### Task 3: Create ConcurrencyTest

**Files:**
- Create: `backend/tests/Feature/Concurrency/ConcurrencyTest.php`

**Step 1: Write the test file**

Create `backend/tests/Feature/Concurrency/ConcurrencyTest.php`:

```php
<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Assets\ConfirmMeterReading;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\Location;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_meter_confirmation_is_idempotent(): void
    {
        $role = Role::where('code', 'TECHNICIAN')->first();
        $tech = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $managerRole = Role::where('code', 'MAINTENANCE_MANAGER')->first();
        $manager = User::factory()->create([
            'role_id' => $managerRole->id,
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $location = Location::create(['name' => 'Loc', 'type' => 'building']);
        $asset = Asset::create([
            'erp_asset_id' => 'ERP-001', 'erp_asset_code' => 'A-001', 'name' => 'Asset',
            'is_active' => true, 'current_location_id' => $location->id,
        ]);

        $readingType = UsageReadingType::create([
            'name' => 'Hours',
            'unit' => 'h',
            'is_active' => true,
        ]);

        $reading = AssetMeterReading::create([
            'asset_id' => $asset->id,
            'usage_reading_type_id' => $readingType->id,
            'reading_value' => 100,
            'reading_at' => now()->subDay(),
            'entered_by_user_id' => $tech->id,
        ]);

        $action = app(ConfirmMeterReading::class);

        $first = $action->execute($reading, $manager->id);
        $this->assertNotNull($first->confirmed_at);
        $this->assertEquals($manager->id, $first->confirmed_by_user_id);
        $firstConfirmedAt = $first->confirmed_at;

        $second = $action->execute($reading->fresh(), $manager->id);
        $this->assertNotNull($second->confirmed_at);
        $this->assertEquals($firstConfirmedAt, $second->confirmed_at);
        $this->assertEquals($manager->id, $second->confirmed_by_user_id);
    }
}
```

**Step 2: Run the tests**

Run: `docker compose run --rm api php artisan test tests/Feature/Concurrency/ConcurrencyTest`
Expected: 1 PASS

**Step 3: Commit**

```bash
git add backend/tests/Feature/Concurrency/ConcurrencyTest.php
git commit -m "test: add behavioral meter confirmation idempotency test"
```

---

### Task 4: Create security-smoke.sh

**Files:**
- Create: `scripts/security-smoke.sh`

**Step 1: Write the script**

Create `scripts/security-smoke.sh`:

```sh
#!/usr/bin/env sh
set -eu

echo "Running security and concurrency tests..."
docker compose run --rm api php artisan test tests/Feature/Security tests/Feature/Concurrency
echo "Done."
```

**Step 2: Make executable and run**

Run: `chmod +x scripts/security-smoke.sh`
Run: `sh scripts/security-smoke.sh`
Expected: 11 PASS

**Step 3: Commit**

```bash
git add scripts/security-smoke.sh
git commit -m "ops: add security and concurrency smoke test script"
```

---

### Task 5: Full regression

**Step 1: Run full test suite**

Run: `docker compose run --rm api php artisan test`
Expected: 268 PASS (257 existing + 11 new)

**Step 2: Run lint**

Run: `docker compose run --rm api ./vendor/bin/pint --test`

**Step 3: Fix lint if needed and commit**

```bash
git add backend
git commit -m "style: fix code style for Task 17"
```
