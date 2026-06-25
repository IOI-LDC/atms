<?php

namespace Tests\Unit\AssetTag;

use App\Models\Asset;
use App\Models\FaSubclassTypeCode;
use App\Services\AssetTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetTagServiceTest extends TestCase
{
    use RefreshDatabase;

    private AssetTagService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AssetTagService;
    }

    public function test_generates_tag_with_fractional_size(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'MTR', 'type_code' => 'MTR']);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-001',
            'name' => 'Motor',
            'description' => '9 5/8" diameter',
            'serial_number' => 'SN-0011',
            'fa_subclass_code' => 'MTR',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertEquals('L-MTR-958-0011', $tag);
    }

    public function test_generates_tag_with_decimal_size(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'PMP', 'type_code' => 'PMP']);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-002',
            'name' => 'Pump',
            'description' => '1.25" inlet',
            'serial_number' => 'A1',
            'fa_subclass_code' => 'PMP',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertEquals('L-PMP-114-0001', $tag);
    }

    public function test_generates_tag_with_whole_size(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'GEN', 'type_code' => 'GEN']);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-003',
            'name' => 'Generator',
            'description' => '8" fan',
            'serial_number' => 'M7-962-0011',
            'fa_subclass_code' => 'GEN',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertEquals('L-GEN-800-0011', $tag);
    }

    public function test_generates_tag_with_no_size(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'HVA', 'type_code' => 'HVA']);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-004',
            'name' => 'HVAC',
            'description' => 'No size mentioned',
            'serial_number' => 'S-42',
            'fa_subclass_code' => 'HVA',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertEquals('L-HVA-000-0042', $tag);
    }

    public function test_generates_tag_with_unknown_type_code(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-005',
            'name' => 'Unknown',
            'description' => '6 3/4"',
            'serial_number' => 'AB-1234',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertEquals('L-UNK-634-1234', $tag);
    }

    public function test_returns_null_on_collision(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'MTR', 'type_code' => 'MTR']);
        Asset::create([
            'erp_asset_code' => 'AST-EXISTING',
            'name' => 'Existing',
            'description' => '8"',
            'serial_number' => '0011',
            'fa_subclass_code' => 'MTR',
            'asset_tag' => 'L-MTR-800-0011',
        ]);

        $asset = Asset::create([
            'erp_asset_code' => 'AST-NEW',
            'name' => 'New',
            'description' => '8"',
            'serial_number' => '0011',
            'fa_subclass_code' => 'MTR',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertNull($tag);
    }

    public function test_serial_suffix_pads_short_values(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'TST', 'type_code' => 'TST']);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-006',
            'name' => 'Test',
            'serial_number' => 'A',
            'fa_subclass_code' => 'TST',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertStringEndsWith('-0000', $tag);
    }

    public function test_serial_suffix_strips_special_chars(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'TST', 'type_code' => 'TST']);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-007',
            'name' => 'Test',
            'serial_number' => 'X-Y-99',
            'fa_subclass_code' => 'TST',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertStringEndsWith('-0099', $tag);
    }

    public function test_rotor_keyword_detection_produces_rtr(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-R01',
            'name' => 'Rotor',
            'description' => '2-7/8" 9/10 1.4 ROTOR',
            'serial_number' => 'SN-0011',
            'fa_subclass_code' => 'MUD MOTOR',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertStringStartsWith('L-RTR-', $tag);
        $this->assertEquals('L-RTR-278-0011', $tag);
    }

    public function test_stator_keyword_detection_produces_str(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-S01',
            'name' => 'Stator',
            'description' => '6 3/4" STATOR ASSEMBLY',
            'serial_number' => 'SN-0022',
            'fa_subclass_code' => 'MUD MOTOR',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertStringStartsWith('L-STR-', $tag);
        $this->assertEquals('L-STR-634-0022', $tag);
    }

    public function test_motor_without_keyword_stays_mtr(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'MUD MOTOR', 'type_code' => 'MTR']);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-M01',
            'name' => 'Motor',
            'description' => '8" Mud Lubed Lower End + Top Sub Only',
            'serial_number' => 'SN-0033',
            'fa_subclass_code' => 'MUD MOTOR',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertStringStartsWith('L-MTR-', $tag);
    }

    public function test_size_code_truncates_from_right_when_exceeds_3_chars(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'MTR', 'type_code' => 'MTR']);
        $asset = Asset::create([
            'erp_asset_code' => 'AST-008',
            'name' => 'Large',
            'description' => '12 1/2" assembly',
            'serial_number' => '0003',
            'fa_subclass_code' => 'MTR',
        ]);

        $tag = $this->service->generateTag($asset);

        // "12 1/2" → cleaned "1212" → truncated to 3 from right → "212"
        $this->assertEquals('L-MTR-212-0003', $tag);
    }

    public function test_falls_back_to_erp_code_when_no_serial(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'MTR', 'type_code' => 'MTR']);
        $asset = Asset::create([
            'erp_asset_code' => 'FA000411',
            'name' => 'Rotor',
            'description' => '2-7/8" 9/10 1.4 ROTOR',
            'serial_number' => null,
            'fa_subclass_code' => 'MUD MOTOR',
        ]);

        $tag = $this->service->generateTag($asset);

        // RTR from keyword, 278 from 2-7/8", 0411 from FA000411
        $this->assertEquals('L-RTR-278-0411', $tag);
    }

    public function test_erp_code_fallback_pads_short_codes(): void
    {
        FaSubclassTypeCode::create(['fa_subclass_code' => 'MTR', 'type_code' => 'MTR']);
        $asset = Asset::create([
            'erp_asset_code' => 'FA000042',
            'name' => 'Motor',
            'description' => '8" assembly',
            'serial_number' => null,
            'fa_subclass_code' => 'MTR',
        ]);

        $tag = $this->service->generateTag($asset);

        $this->assertEquals('L-MTR-800-0042', $tag);
    }

    public function test_hyphen_separated_fraction_size(): void
    {
        $asset = Asset::create([
            'erp_asset_code' => 'AST-009',
            'name' => 'Rotor',
            'description' => '2-7/8" 9/10 1.4 ROTOR',
            'serial_number' => null,
        ]);

        $tag = $this->service->generateTag($asset);

        // 2-7/8" → cleaned "278" → size "278", RTR from keyword
        $this->assertStringStartsWith('L-RTR-278-', $tag);
    }
}
