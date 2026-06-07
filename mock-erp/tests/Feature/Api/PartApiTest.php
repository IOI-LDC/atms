<?php

namespace Tests\Feature\Api;

use App\Models\Part;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mock-erp.service_api_key' => 'test-secret']);
    }

    public function test_missing_api_key_returns_401(): void
    {
        $this->getJson('/api/parts')->assertStatus(401);
    }

    public function test_invalid_api_key_returns_401(): void
    {
        $this->withHeader('X-Service-API-Key', 'wrong-key')
             ->getJson('/api/parts')
             ->assertStatus(401);
    }

    public function test_valid_api_key_allows_access(): void
    {
        $this->withHeader('X-Service-API-Key', 'test-secret')
             ->getJson('/api/parts')
             ->assertOk();
    }

    public function test_invalid_updated_since_returns_422(): void
    {
        $this->withHeader('X-Service-API-Key', 'test-secret')
             ->getJson('/api/parts?updated_since=not-a-date')
             ->assertStatus(422);
    }

    public function test_part_fields_match_contract(): void
    {
        Part::create([
            'code' => 'PRT-TEST',
            'name' => 'Test Part',
            'description' => 'Test desc',
            'unit_of_measure' => 'EA',
            'category' => 'Test',
            'status' => 'active',
        ]);

        $response = $this->withHeader('X-Service-API-Key', 'test-secret')
                         ->getJson('/api/parts');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'code', 'name', 'description', 'unit_of_measure',
                    'category', 'status', 'updated_at'
                ]
            ]
        ]);
    }

    public function test_updated_since_filters_correctly(): void
    {
        $oldDate = now()->subDays(10);
        $newDate = now();

        Part::create(['code' => 'OLD', 'name' => 'Old', 'created_at' => $oldDate, 'updated_at' => $oldDate]);
        Part::create(['code' => 'NEW', 'name' => 'New', 'created_at' => $newDate, 'updated_at' => $newDate]);

        $response = $this->withHeader('X-Service-API-Key', 'test-secret')
                         ->getJson('/api/parts?updated_since=' . urlencode(now()->subDays(5)->toIso8601String()));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('NEW', $response->json('data.0.code'));
    }

    public function test_cursor_pagination(): void
    {
        for ($i = 0; $i < 15; $i++) {
            Part::create(['code' => "P{$i}", 'name' => "Part {$i}"]);
        }

        $response = $this->withHeader('X-Service-API-Key', 'test-secret')
                         ->getJson('/api/parts?limit=10');
        
        $response->assertOk();
        $this->assertCount(10, $response->json('data'));
        $this->assertNotNull($response->json('next_cursor'));
        $this->assertNotNull($response->json('next_page_url'));
    }
}
