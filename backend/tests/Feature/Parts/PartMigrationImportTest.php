<?php

namespace Tests\Feature\Parts;

use App\Models\MaintenanceCategory;
use App\Models\Part;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartMigrationImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Part::query()->delete();
        MaintenanceCategory::query()->delete();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_dry_run_validates_without_writing_changes(): void
    {
        $part = $this->createPart([
            'erp_part_code' => 'PRT-001',
            'name' => 'Original Name',
            'available_quantity' => 2,
        ]);
        $path = $this->writeCsv([
            $this->validRow($part, [
                'cleaned_name' => 'Updated Name',
                'available_quantity' => '9.500',
                'proposed_maintenance_category_name' => 'MWD / APS',
                'proposed_size' => '6 3/4"',
                'proposed_size_inches' => '6.75000',
                'proposed_part_number' => 'PN-100',
            ]),
        ]);

        $this->artisan('atms:import-parts', [
            'file' => $path,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Validation passed.')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(Command::SUCCESS);

        $part->refresh();

        $this->assertSame('Original Name', $part->name);
        $this->assertSame('2.000', $part->available_quantity);
        $this->assertNull($part->maintenance_category_id);
        $this->assertNull($part->size_inches);
        $this->assertNull($part->part_number);
        $this->assertDatabaseCount('maintenance_categories', 0);
    }

    public function test_import_updates_existing_parts_and_creates_controlled_categories(): void
    {
        $part = $this->createPart([
            'erp_part_code' => 'PRT-002',
            'name' => 'Original Name',
            'available_quantity' => 2,
        ]);
        $path = $this->writeCsv([
            $this->validRow($part, [
                'cleaned_name' => 'Updated Name',
                'available_quantity' => '9.500',
                'status' => 'inactive',
                'is_active' => 'false',
                'proposed_maintenance_category_name' => 'MWD / APS',
                'proposed_size' => '1 1/2"',
                'proposed_size_inches' => '1.50000',
                'proposed_part_number' => 'PN-200',
            ]),
        ]);

        $this->artisan('atms:import-parts', ['file' => $path])
            ->expectsOutputToContain('Imported. 1 parts updated')
            ->assertExitCode(Command::SUCCESS);

        $part->refresh();
        $category = MaintenanceCategory::where('code', 'MWD_APS')->firstOrFail();

        $this->assertSame('Updated Name', $part->name);
        $this->assertSame('9.500', $part->available_quantity);
        $this->assertSame('inactive', $part->erp_status);
        $this->assertFalse($part->is_active);
        $this->assertSame('PN-200', $part->part_number);
        $this->assertSame('1.50000', $part->size_inches->canonical());
        $this->assertSame($category->id, $part->maintenance_category_id);
        $this->assertSame('PRT-002', $part->erp_part_code);
    }

    public function test_validation_failure_keeps_every_part_unchanged(): void
    {
        $first = $this->createPart([
            'erp_part_code' => 'PRT-003',
            'name' => 'First Original',
        ]);
        $second = $this->createPart([
            'erp_part_code' => 'PRT-004',
            'name' => 'Second Original',
        ]);
        $path = $this->writeCsv([
            $this->validRow($first, ['cleaned_name' => 'First Updated']),
            $this->validRow($second, [
                'status' => 'bogus',
                'is_active' => 'true',
                'cleaned_name' => 'Second Updated',
            ]),
        ]);

        $this->artisan('atms:import-parts', ['file' => $path])
            ->expectsOutputToContain('validation error')
            ->expectsOutputToContain('invalid status')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame('First Original', $first->refresh()->name);
        $this->assertSame('Second Original', $second->refresh()->name);
        $this->assertDatabaseCount('maintenance_categories', 0);
    }

    public function test_import_applies_erp_code_rename_when_system_id_matches(): void
    {
        $part = $this->createPart([
            'erp_part_code' => 'PRT-010',
            'name' => 'Renamed Code Part',
        ]);
        $path = $this->writeCsv([
            $this->validRow($part, [
                'erp_part_code' => 'PRT-010X',
                'cleaned_name' => 'Renamed Code Part',
            ]),
        ]);

        $this->artisan('atms:import-parts', ['file' => $path])
            ->expectsOutputToContain('1 parts updated')
            ->assertExitCode(Command::SUCCESS);

        $part->refresh();

        $this->assertSame('PRT-010X', $part->erp_part_code);
        $this->assertDatabaseCount('parts', 1);
    }

    public function test_import_rejects_code_rename_that_collides_with_another_part(): void
    {
        $owner = $this->createPart([
            'erp_part_code' => 'PRT-011',
            'name' => 'Owner Part',
        ]);
        $renamed = $this->createPart([
            'erp_part_code' => 'PRT-012',
            'name' => 'Rename Target',
        ]);
        $path = $this->writeCsv([
            // renaming PRT-012 to PRT-011 is rejected: that code belongs to owner
            $this->validRow($renamed, [
                'erp_part_code' => 'PRT-011',
                'cleaned_name' => 'Rename Target',
            ]),
        ]);

        $this->artisan('atms:import-parts', ['file' => $path])
            ->expectsOutputToContain('validation error')
            ->expectsOutputToContain('is already assigned to ERP item')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame('PRT-011', $owner->refresh()->erp_part_code);
        $this->assertSame('PRT-012', $renamed->refresh()->erp_part_code);
        $this->assertDatabaseCount('parts', 2);
    }

    public function test_rerunning_import_does_not_touch_unchanged_parts(): void
    {
        $part = $this->createPart([
            'erp_part_code' => 'PRT-005',
            'name' => 'Original Name',
        ]);
        $path = $this->writeCsv([
            $this->validRow($part, ['cleaned_name' => 'Updated Name']),
        ]);

        $this->artisan('atms:import-parts', ['file' => $path])
            ->expectsOutputToContain('1 parts updated, 0 unchanged')
            ->assertExitCode(Command::SUCCESS);

        $updatedAt = $part->refresh()->updated_at;
        $this->travel(1)->minute();

        $this->artisan('atms:import-parts', ['file' => $path])
            ->expectsOutputToContain('0 parts updated, 1 unchanged')
            ->assertExitCode(Command::SUCCESS);

        $this->assertTrue($part->refresh()->updated_at->equalTo($updatedAt));
    }

    public function test_import_creates_new_parts_with_erp_system_id(): void
    {
        $systemId = (string) Str::uuid();
        $path = $this->writeCsv([
            $this->newRow([
                'erp_system_id' => $systemId,
                'erp_part_code' => 'NEW-001',
                'cleaned_name' => 'New Part One',
                'available_quantity' => '4',
            ]),
        ]);

        $this->artisan('atms:import-parts', ['file' => $path])
            ->expectsOutputToContain('0 parts updated, 0 unchanged, 1 created')
            ->assertExitCode(Command::SUCCESS);

        $part = Part::where('erp_part_code', 'NEW-001')->firstOrFail();

        $this->assertSame($systemId, $part->erp_part_id);
        $this->assertSame('New Part One', $part->name);
        $this->assertSame('4.000', $part->available_quantity);
        $this->assertDatabaseCount('parts', 1);
    }

    public function test_import_creates_new_parts_without_erp_system_id_and_is_idempotent(): void
    {
        $path = $this->writeCsv([
            $this->newRow([
                'erp_system_id' => '',
                'erp_part_code' => 'NEW-002',
                'cleaned_name' => 'New Part Two',
            ]),
        ]);

        $this->artisan('atms:import-parts', ['file' => $path])
            ->expectsOutputToContain('0 parts updated, 0 unchanged, 1 created')
            ->assertExitCode(Command::SUCCESS);

        $part = Part::where('erp_part_code', 'NEW-002')->firstOrFail();
        $this->assertNull($part->erp_part_id);

        $updatedAt = $part->refresh()->updated_at;
        $this->travel(1)->minute();

        $this->artisan('atms:import-parts', ['file' => $path])
            ->expectsOutputToContain('0 parts updated, 1 unchanged, 0 created')
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('parts', 1);
        $this->assertTrue($part->refresh()->updated_at->equalTo($updatedAt));
    }

    public function test_import_rejects_code_already_assigned_to_another_erp_item(): void
    {
        $part = $this->createPart([
            'erp_part_code' => 'PRT-006',
            'name' => 'Existing Part',
        ]);
        $path = $this->writeCsv([
            $this->newRow([
                'erp_system_id' => '',
                'erp_part_code' => 'PRT-006',
                'cleaned_name' => 'Trying to steal the code',
            ]),
        ]);

        $this->artisan('atms:import-parts', ['file' => $path])
            ->expectsOutputToContain('is already assigned to ERP item')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame('Existing Part', $part->refresh()->name);
        $this->assertDatabaseCount('parts', 1);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function newRow(array $overrides = []): array
    {
        return array_merge([
            'erp_system_id' => '',
            'erp_part_code' => 'NEW-DEFAULT',
            'cleaned_name' => 'New Part',
            'available_quantity' => '0',
            'status' => 'active',
            'is_active' => 'true',
            'proposed_maintenance_category_name' => '',
            'proposed_size' => '',
            'proposed_size_inches' => '',
            'proposed_part_number' => '',
            'requires_review' => 'false',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPart(array $overrides = []): Part
    {
        return Part::create(array_merge([
            'erp_part_id' => (string) Str::uuid(),
            'erp_part_code' => 'PRT-DEFAULT',
            'name' => 'Part',
            'unit_of_measure' => 'PCS',
            'available_quantity' => 0,
            'erp_status' => 'active',
            'is_active' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validRow(Part $part, array $overrides = []): array
    {
        return array_merge([
            'erp_system_id' => (string) $part->erp_part_id,
            'erp_part_code' => $part->erp_part_code,
            'cleaned_name' => $part->name,
            'available_quantity' => (string) $part->available_quantity,
            'status' => 'active',
            'is_active' => 'true',
            'proposed_maintenance_category_name' => '',
            'proposed_size' => '',
            'proposed_size_inches' => '',
            'proposed_part_number' => '',
            'requires_review' => 'false',
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'atms-parts-');
        $this->assertNotFalse($path);
        $this->temporaryFiles[] = $path;

        $handle = fopen($path, 'w');
        $this->assertNotFalse($handle);

        fputcsv($handle, array_keys($rows[0]), escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);

        return $path;
    }
}
