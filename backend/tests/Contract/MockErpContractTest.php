<?php

namespace Tests\Contract;

use App\Contracts\Erp\ErpSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MockErpContractTest extends TestCase
{
    public function test_mock_erp_http_source_handles_pagination_and_mapping(): void
    {
        Http::fake([
            '*/api/parts*' => Http::response([
                'data' => [
                    [
                        'id' => 10,
                        'code' => 'PRT-100',
                        'name' => 'Filter',
                        'description' => 'Air filter',
                        'unit_of_measure' => 'EA',
                        'category' => 'Consumables',
                        'status' => 'active',
                        'updated_at' => '2026-06-07T10:00:00Z',
                    ]
                ],
                'next_cursor' => 'next-page'
            ])
        ]);

        $source = app(ErpSource::class);
        $result = $source->getParts(null, null, 10);

        $this->assertCount(1, $result['data']);
        $this->assertEquals('next-page', $result['next_cursor']);
        
        $part = $result['data'][0];
        $this->assertInstanceOf(\App\Data\Erp\ExternalPartData::class, $part);
        $this->assertEquals('PRT-100', $part->code);
        $this->assertEquals('EA', $part->unitOfMeasure);
    }
}
