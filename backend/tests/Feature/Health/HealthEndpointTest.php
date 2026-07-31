<?php

namespace Tests\Feature\Health;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_returns_200(): void
    {
        $response = $this->getJson('/api/health/live');

        $response->assertOk();
        $response->assertJson(['status' => 'alive']);
    }

    public function test_readiness_checks_database_and_disk(): void
    {
        $response = $this->getJson('/api/health/ready');

        $response->assertOk();
        $response->assertJsonStructure(['status', 'database', 'attachments']);
    }

    public function test_health_response_contains_no_secrets(): void
    {
        $responses = [
            $this->getJson('/api/health/live'),
            $this->getJson('/api/health/ready'),
        ];

        foreach ($responses as $response) {
            $content = $response->getContent();
            $this->assertStringNotContainsString('APP_KEY', $content);
            $this->assertStringNotContainsString('password', $content);
            $this->assertStringNotContainsString('secret', $content);
            $this->assertStringNotContainsString('DB_PASSWORD', $content);
        }
    }
}
