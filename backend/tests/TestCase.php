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
     * Satisfy the close-time attachment requirement (RQ2, 2026-08-16).
     *
     * A work order cannot be closed until it carries at least one attachment.
     * Tests about anything *other* than that rule should call this rather than
     * restate it — the same reasoning as `workshopLocation()` above.
     *
     * The row is written directly instead of going through the upload endpoint:
     * the gate checks presence, not content, and no test that merely needs to
     * reach `closed` cares about bytes on disk. `AttachmentGateTest` covers the
     * rule itself through the real API.
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
