<?php

namespace Tests;

use App\Enums\LocationType;
use App\Models\Location;
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
}
