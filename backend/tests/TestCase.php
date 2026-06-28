<?php

namespace Tests;

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
}
