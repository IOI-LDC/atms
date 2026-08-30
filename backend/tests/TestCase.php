<?php

namespace Tests;

use App\Enums\LocationType;
use App\Models\Attachment;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stateful SPA auth routes (login/logout) run through Sanctum's pipeline,
        // which includes ValidateCsrfToken, when an Origin header is present.
        // Tests don't seed a real XSRF-TOKEN cookie, so disable CSRF verification
        // in the test environment. No test in the suite asserts CSRF (419) behavior.
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * A workshop for tests that need an asset somewhere a work order may start.
     *
     * `StartWorkOrder` refuses to start work on an asset that is not at a
     * workshop or yard. Tests about anything other than that guard should place
     * their assets here rather than restate the rule.
     */
    protected function workshopLocation(): Location
    {
        return Location::firstOrCreate(
            ['code' => 'WS'],
            ['name' => 'Test Workshop', 'type' => LocationType::WORKSHOP->value, 'is_active' => true],
        );
    }

    /**
     * Give a work order an attachment directly in the database.
     *
     * Attachments are optional at close (2026-08-30), so tests only need this
     * when the attachment itself, an upload audit, or the terminal locks are
     * under test — tests that merely need to reach `closed` may close without
     * one. The row is written directly instead of going through the upload
     * endpoint, because no test that just needs the row cares about bytes on
     * disk; `WorkOrderAttachmentGateTest` covers the real API.
     */
    protected function attachToWorkOrder(WorkOrder $workOrder, ?User $uploadedBy = null): Attachment
    {
        return Attachment::create([
            'attachable_type' => 'work_order',
            'attachable_id' => $workOrder->id,
            'original_name' => 'inspection-form.pdf',
            'stored_path' => 'work-orders/'.$workOrder->id.'/inspection-form.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'file_hash' => hash('sha256', 'wo-'.$workOrder->id),
            'uploaded_by_user_id' => $uploadedBy?->id,
        ]);
    }
}
