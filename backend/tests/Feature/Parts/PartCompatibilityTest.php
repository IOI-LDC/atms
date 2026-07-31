<?php

namespace Tests\Feature\Parts;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\MaintenanceCategory;
use App\Models\MaintenanceRequest;
use App\Models\Part;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Compatible-parts filtering.
 *
 *     (part category is blank OR matches the asset category)
 * AND (part size is blank OR matches the asset size)
 *
 * Enforced server-side in two places: the list endpoint narrows what the picker
 * offers, and RecordWorkOrderPart repeats the check so a direct API call cannot
 * bypass it.
 */
class PartCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private MaintenanceCategory $motor;

    private MaintenanceCategory $jar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->motor = MaintenanceCategory::factory()->create(['code' => 'MOTOR', 'name' => 'Motor']);
        $this->jar = MaintenanceCategory::factory()->create(['code' => 'JAR', 'name' => 'Jar']);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR)->first()->id,
            'is_active' => true,
        ]);
    }

    private function asset(?int $categoryId, ?string $size): Asset
    {
        return Asset::create([
            'erp_asset_code' => 'AST-CMP-'.uniqid(),
            'name' => 'Test Asset',
            'maintenance_category_id' => $categoryId,
            'size_inches' => $size,
            'is_active' => true,
        ]);
    }

    private function part(string $name, ?int $categoryId, ?string $size, float $qty = 5, bool $active = true): Part
    {
        return Part::create([
            'erp_part_code' => 'PRT-'.uniqid(),
            'name' => $name,
            'maintenance_category_id' => $categoryId,
            'size_inches' => $size,
            'available_quantity' => $qty,
            'is_active' => $active,
        ]);
    }

    private function compatibleNames(Asset $asset): array
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/parts?compatible_with_asset_id='.$asset->id)
            ->assertOk();

        return collect($response->json('data'))->pluck('name')->sort()->values()->all();
    }

    /**
     * The four wildcard combinations from the approved rule, against an asset
     * that has both a category and a size.
     */
    public function test_all_four_wildcard_combinations_against_a_fully_specified_asset(): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');

        $this->part('exact match', $this->motor->id, '6 3/4');
        $this->part('category wildcard size', $this->motor->id, null);
        $this->part('size wildcard category', null, '6 3/4');
        $this->part('universal', null, null);
        $this->part('wrong category', $this->jar->id, '6 3/4');
        $this->part('wrong size', $this->motor->id, '8');
        $this->part('wrong both', $this->jar->id, '8');

        $this->assertSame(
            ['category wildcard size', 'exact match', 'size wildcard category', 'universal'],
            $this->compatibleNames($asset),
        );
    }

    /**
     * When the asset itself is missing a dimension, only a part blank on that
     * same dimension can match. This is why the rule must not be written as
     * `IS NOT DISTINCT FROM`, which would instead match blank-to-blank as equal
     * and let a sized part through.
     */
    public function test_asset_missing_a_category_matches_only_category_blank_parts(): void
    {
        $asset = $this->asset(null, '6 3/4');

        $this->part('blank category exact size', null, '6 3/4');
        $this->part('blank both', null, null);
        $this->part('has category', $this->motor->id, '6 3/4');

        $this->assertSame(
            ['blank both', 'blank category exact size'],
            $this->compatibleNames($asset),
        );
    }

    public function test_asset_missing_a_size_matches_only_size_blank_parts(): void
    {
        $asset = $this->asset($this->motor->id, null);

        $this->part('exact category blank size', $this->motor->id, null);
        $this->part('blank both', null, null);
        $this->part('has size', $this->motor->id, '6 3/4');

        $this->assertSame(
            ['blank both', 'exact category blank size'],
            $this->compatibleNames($asset),
        );
    }

    public function test_asset_missing_both_matches_only_universal_parts(): void
    {
        $asset = $this->asset(null, null);

        $this->part('universal', null, null);
        $this->part('has category', $this->motor->id, null);
        $this->part('has size', null, '6 3/4');

        $this->assertSame(['universal'], $this->compatibleNames($asset));
    }

    public function test_size_matching_is_canonical_not_textual(): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');

        $this->part('decimal spelling', $this->motor->id, '6.75');
        $this->part('inch mark spelling', $this->motor->id, '6.7500"');

        $this->assertSame(
            ['decimal spelling', 'inch mark spelling'],
            $this->compatibleNames($asset),
        );
    }

    public function test_inactive_parts_are_never_offered(): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');

        $this->part('active', $this->motor->id, '6 3/4');
        $this->part('inactive', $this->motor->id, '6 3/4', active: false);

        $this->assertSame(['active'], $this->compatibleNames($asset));
    }

    /**
     * Zero availability stays visible so the requester can see the part exists —
     * the picker disables it and the submit path rejects it.
     */
    public function test_out_of_stock_parts_remain_visible_in_the_list(): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');

        $this->part('in stock', $this->motor->id, '6 3/4', qty: 5);
        $this->part('out of stock', $this->motor->id, '6 3/4', qty: 0);

        $this->assertSame(['in stock', 'out of stock'], $this->compatibleNames($asset));
    }

    /**
     * Most specific first: both match, then size only, then category only, then
     * universal. Size outranks category because size is the hard physical
     * constraint — a part either fits the bore or it does not.
     */
    public function test_compatible_parts_are_ordered_most_specific_first(): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');

        // Created deliberately out of order, and named so that an accidental
        // alphabetical sort would produce the exact reverse.
        $this->part('a universal', null, null);
        $this->part('b category only', $this->motor->id, null);
        $this->part('c size only', null, '6 3/4');
        $this->part('d both match', $this->motor->id, '6 3/4');

        $this->assertSame(
            ['d both match', 'c size only', 'b category only', 'a universal'],
            collect(
                $this->actingAs($this->admin())
                    ->getJson('/api/parts?compatible_with_asset_id='.$asset->id)
                    ->assertOk()
                    ->json('data')
            )->pluck('name')->all(),
        );
    }

    /**
     * An out-of-stock part is shown but disabled, so it must not outrank a part
     * that can actually be requested from the same bucket.
     */
    public function test_in_stock_parts_come_first_within_a_bucket(): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');

        $this->part('a empty', $this->motor->id, '6 3/4', qty: 0);
        $this->part('b stocked', $this->motor->id, '6 3/4', qty: 4);

        $this->assertSame(
            ['b stocked', 'a empty'],
            collect(
                $this->actingAs($this->admin())
                    ->getJson('/api/parts?compatible_with_asset_id='.$asset->id)
                    ->assertOk()
                    ->json('data')
            )->pluck('name')->all(),
        );
    }

    /**
     * With no category or size on the asset only universal parts are compatible,
     * so the ranking collapses to one bucket and must not error.
     */
    public function test_ordering_degrades_cleanly_for_an_asset_with_no_category_or_size(): void
    {
        $asset = $this->asset(null, null);

        $this->part('b second', null, null);
        $this->part('a first', null, null);

        $this->assertSame(['a first', 'b second'], $this->compatibleNames($asset));
    }

    /**
     * The specificity ordering must stay primary — a caller-supplied sort cannot
     * demote it to a tiebreak.
     */
    public function test_an_explicit_sort_does_not_override_specificity(): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');

        $this->part('a universal', null, null);
        $this->part('z both match', $this->motor->id, '6 3/4');

        $names = collect(
            $this->actingAs($this->admin())
                ->getJson('/api/parts?compatible_with_asset_id='.$asset->id.'&sort=name:asc')
                ->assertOk()
                ->json('data')
        )->pluck('name')->all();

        $this->assertSame(['z both match', 'a universal'], $names);
    }

    public function test_unfiltered_list_is_unchanged(): void
    {
        $this->part('a', $this->motor->id, '6 3/4');
        $this->part('b', $this->jar->id, '8');

        $response = $this->actingAs($this->admin())->getJson('/api/parts')->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_unknown_asset_id_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/parts?compatible_with_asset_id=999999')
            ->assertStatus(422);
    }

    // --- Submit-time enforcement -------------------------------------------

    private function workOrderFor(Asset $asset): WorkOrder
    {
        $admin = $this->admin();

        $mr = MaintenanceRequest::create([
            'number' => 'MR-'.uniqid(),
            'asset_id' => $asset->id,
            'status' => 'converted',
            'priority' => 'medium',
            'description' => 'Test',
            'created_by' => $admin->id,
            'is_preventive' => false,
        ]);

        return WorkOrder::create([
            'number' => 'WO-'.uniqid(),
            'maintenance_request_id' => $mr->id,
            'asset_id' => $asset->id,
            'status' => 'open',
            'priority' => 'medium',
            'description' => 'Test',
        ]);
    }

    private function addPart(WorkOrder $workOrder, Part $part): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin())
            ->postJson("/api/work-orders/{$workOrder->id}/parts", [
                'part_id' => $part->id,
                'quantity' => 1,
            ]);
    }

    public function test_compatible_part_can_be_added(): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');

        $this->addPart($this->workOrderFor($asset), $this->part('ok', $this->motor->id, '6 3/4'))
            ->assertCreated();
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function incompatiblePartProvider(): array
    {
        return [
            'wrong category' => ['jar', '6 3/4'],
            'wrong size' => ['motor', '8'],
            'wrong both' => ['jar', '8'],
        ];
    }

    #[DataProvider('incompatiblePartProvider')]
    public function test_incompatible_part_is_rejected_on_submit(string $category, ?string $size): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');
        $categoryId = $category === 'motor' ? $this->motor->id : $this->jar->id;

        $this->addPart($this->workOrderFor($asset), $this->part('bad', $categoryId, $size))
            ->assertStatus(409)
            ->assertJsonPath('message', 'That part is not compatible with this work order\'s asset.');
    }

    public function test_out_of_stock_part_is_rejected_on_submit(): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');

        $this->addPart($this->workOrderFor($asset), $this->part('empty', $this->motor->id, '6 3/4', qty: 0))
            ->assertStatus(409)
            ->assertJsonPath('message', 'That part is out of stock and cannot be requested.');
    }

    public function test_inactive_part_is_rejected_on_submit(): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');

        $this->addPart($this->workOrderFor($asset), $this->part('dead', $this->motor->id, '6 3/4', active: false))
            ->assertStatus(409)
            ->assertJsonPath('message', 'That part is inactive and cannot be requested.');
    }

    public function test_recording_consumption_does_not_decrement_the_erp_snapshot(): void
    {
        $asset = $this->asset($this->motor->id, '6 3/4');
        $part = $this->part('snapshot', $this->motor->id, '6 3/4', qty: 5);

        $this->addPart($this->workOrderFor($asset), $part)->assertCreated();

        $this->assertSame('5.000', $part->refresh()->available_quantity);
    }
}
