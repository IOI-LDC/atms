<?php

namespace Tests\Feature\Api;

use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mock-erp.service_api_key' => 'test-secret']);
    }

    public function test_missing_api_key_returns_401(): void
    {
        $this->getJson('/api/assets')->assertStatus(401);
    }

    public function test_invalid_api_key_returns_401(): void
    {
        $this->withHeader('X-Service-API-Key', 'wrong-key')
             ->getJson('/api/assets')
             ->assertStatus(401);
    }

    public function test_valid_api_key_allows_access(): void
    {
        $this->withHeader('X-Service-API-Key', 'test-secret')
             ->getJson('/api/assets')
             ->assertOk();
    }

    public function test_asset_fields_match_contract(): void
    {
        Asset::create([
            'code' => 'AST-TEST',
            'name' => 'Test Asset',
            'description' => 'Test desc',
            'serial_number' => 'SN123',
            'category' => 'Test',
            'manufacturer' => 'TestCorp',
            'model' => 'M1',
            'status' => 'active',
        ]);

        $response = $this->withHeader('X-Service-API-Key', 'test-secret')
                         ->getJson('/api/assets');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'code', 'name', 'description', 'serial_number',
                    'category', 'manufacturer', 'model', 'status', 'updated_at'
                ]
            ]
        ]);
    }

    public function test_updated_since_filters_correctly(): void
    {
        $oldDate = now()->subDays(10);
        $newDate = now();

        Asset::create(['code' => 'OLD', 'name' => 'Old', 'created_at' => $oldDate, 'updated_at' => $oldDate]);
        Asset::create(['code' => 'NEW', 'name' => 'New', 'created_at' => $newDate, 'updated_at' => $newDate]);

        $response = $this->withHeader('X-Service-API-Key', 'test-secret')
                         ->getJson('/api/assets?updated_since=' . now()->subDays(5)->toIso8601String());

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('NEW', $response->json('data.0.code'));
    }

    public function test_cursor_pagination(): void
    {
        for ($i = 0; $i < 15; $i++) {
            Asset::create(['code' => "A{$i}", 'name' => "Asset {$i}"]);
        }

        $response = $this->withHeader('X-Service-API-Key', 'test-secret')
                         ->getJson('/api/assets?limit=10');
        
        $response->assertOk();
        $this->assertCount(10, $response->json('data'));
        $this->assertNotNull($response->json('next_cursor'));
        $this->assertNotNull($response->json('next_page_url'));
    }
}
