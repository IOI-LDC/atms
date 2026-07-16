<?php

namespace Tests\Feature\Parts;

use App\Models\Part;
use Database\Seeders\PartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_fifty_five_parts_across_all_categories(): void
    {
        $this->seed(PartSeeder::class);

        $this->assertSame(55, Part::count());
    }

    public function test_seeded_parts_are_active_with_expected_fields(): void
    {
        $this->seed(PartSeeder::class);

        $sample = Part::where('erp_part_code', 'MM-ROT-675')->first();

        $this->assertNotNull($sample);
        $this->assertSame('Rotor 6-3/4"', $sample->name);
        $this->assertSame('each', $sample->unit_of_measure);
        $this->assertSame('Mud Motor', $sample->category);
        $this->assertTrue($sample->is_active);
        $this->assertSame('active', $sample->erp_status);
        // erp_part_id + erp_raw_data stay NULL until the real ERP sync populates them.
        $this->assertNull($sample->erp_part_id);
        $this->assertNull($sample->erp_raw_data);
    }

    public function test_seeder_covers_all_fa_subclass_aligned_categories(): void
    {
        $this->seed(PartSeeder::class);

        $categories = Part::distinct()->pluck('category')->sort()->values()->all();

        // "MWD/LWD" sorts before "Mud Motor" because '/' (ASCII 47) < 'u'.
        $this->assertSame(
            ['Bearings & Seals', 'Completion', 'Consumables', 'Downhole Tools', 'Drill Collars', 'Electrical', 'Filters & Fluids', 'Hydraulics', 'MWD/LWD', 'Mud Motor', 'Wireline'],
            $categories,
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(PartSeeder::class);
        $countAfterFirstRun = Part::count();

        // Re-run — should not create duplicates or change the count.
        $this->seed(PartSeeder::class);

        $this->assertSame($countAfterFirstRun, Part::count());
        $this->assertSame(55, Part::count());
    }

    public function test_erp_part_codes_are_unique(): void
    {
        $this->seed(PartSeeder::class);

        $codes = Part::pluck('erp_part_code');

        $this->assertSame($codes->count(), $codes->unique()->count());
    }

    public function test_does_not_add_placeholder_parts_when_erp_parts_exist(): void
    {
        Part::create([
            'erp_part_id' => '478a85a2-c971-f011-8eef-6045bd6acac2',
            'erp_part_code' => '7HF 400ML',
            'name' => 'ERP Part',
        ]);

        $this->seed(PartSeeder::class);

        $this->assertSame(1, Part::count());
        $this->assertDatabaseMissing('parts', ['erp_part_code' => 'MM-ROT-675']);
    }
}
