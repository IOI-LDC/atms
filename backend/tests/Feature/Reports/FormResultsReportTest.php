<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\FaSubclassTypeCode;
use App\Models\FormTemplate;
use App\Models\FormTemplateField;
use App\Models\Location;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderForm;
use App\Models\WorkOrderFormField;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormResultsReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = $this->createUser(RoleCode::ADMINISTRATOR);
    }

    private function createUser(RoleCode $roleCode): User
    {
        $role = Role::where('code', $roleCode->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function createAsset(array $overrides = []): Asset
    {
        $location = Location::create(['name' => 'Loc-'.uniqid(), 'type' => 'building']);

        return Asset::create(array_merge([
            'erp_asset_code' => 'A-'.uniqid(),
            'name' => 'Asset',
            'is_active' => true,
            'current_location_id' => $location->id,
        ], $overrides));
    }

    private function createWorkOrderWithForm(array $fieldValues = [], string $subclassCode = 'TEST'): WorkOrder
    {
        $asset = $this->createAsset();
        $mr = MaintenanceRequest::forceCreate([
            'asset_id' => $asset->id,
            'number' => 'MR-'.uniqid(),
            'status' => 'converted',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'created_at' => now(),
        ]);

        $wo = WorkOrder::forceCreate([
            'asset_id' => $asset->id,
            'maintenance_request_id' => $mr->id,
            'number' => 'WO-'.uniqid(),
            'status' => 'completed',
            'priority' => 'medium',
            'created_at' => now(),
        ]);

        $faSubclassCode = FaSubclassTypeCode::firstOrCreate(
            ['fa_subclass_code' => $subclassCode],
            ['type_code' => 'TST', 'description' => 'Test subclass']
        );

        $template = FormTemplate::where('fa_subclass_code', $faSubclassCode->fa_subclass_code)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            $template = FormTemplate::create([
                'name' => 'Template-'.uniqid(),
                'fa_subclass_code' => $faSubclassCode->fa_subclass_code,
                'is_active' => true,
            ]);
        }

        $woForm = WorkOrderForm::create([
            'work_order_id' => $wo->id,
            'form_template_id' => $template->id,
            'snapshotted_at' => now(),
        ]);

        foreach ($fieldValues as $fieldData) {
            $fieldUuid = $fieldData['uuid'] ?? (string) Str::uuid();
            $templateField = FormTemplateField::firstOrCreate([
                'form_template_id' => $template->id,
                'uuid' => $fieldUuid,
            ], [
                'label' => $fieldData['label'] ?? 'Field',
                'field_type' => $fieldData['field_type'] ?? 'text',
                'has_pre_post' => $fieldData['has_pre_post'] ?? false,
                'unit' => $fieldData['unit'] ?? null,
                'is_required' => false,
                'sort_order' => 0,
            ]);

            WorkOrderFormField::create([
                'work_order_form_id' => $woForm->id,
                'form_template_field_id' => $templateField->id,
                'uuid' => $templateField->uuid,
                'label' => $templateField->label,
                'field_type' => $templateField->field_type,
                'has_pre_post' => $templateField->has_pre_post,
                'unit' => $templateField->unit,
                'is_required' => $templateField->is_required,
                'sort_order' => $templateField->sort_order,
                'pre_value' => $fieldData['pre_value'] ?? null,
                'post_value' => $fieldData['post_value'] ?? null,
                'notes' => $fieldData['notes'] ?? null,
            ]);
        }

        return $wo;
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/reports/form-results')->assertUnauthorized();
    }

    public function test_calculates_form_results(): void
    {
        $this->createWorkOrderWithForm([
            ['label' => 'Temperature', 'field_type' => 'numeric', 'has_pre_post' => true, 'unit' => '°C', 'pre_value' => '25.5', 'post_value' => '22.0'],
            ['label' => 'Status', 'field_type' => 'boolean', 'post_value' => 'true'],
        ]);

        $this->createWorkOrderWithForm([
            ['label' => 'Temperature', 'field_type' => 'numeric', 'has_pre_post' => true, 'unit' => '°C', 'pre_value' => '30.0', 'post_value' => '24.5'],
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/form-results')->json();

        $this->assertSame(3, $json['summary']['total_fields']);
        $this->assertSame(1, $json['summary']['boolean_true_count']);
        $this->assertCount(3, $json['data']);
        $this->assertArrayHasKey('asset', $json['data'][0]);
        $this->assertArrayHasKey('work_order', $json['data'][0]);
        $this->assertArrayNotHasKey('work_order_form', $json['data'][0]);
    }

    public function test_numeric_summary_groups_by_field_and_unit_and_ignores_invalid_values(): void
    {
        $temperatureUuid = (string) Str::uuid();
        $pressureUuid = (string) Str::uuid();

        $this->createWorkOrderWithForm([
            ['uuid' => $temperatureUuid, 'label' => 'Temperature', 'field_type' => 'numeric', 'has_pre_post' => true, 'unit' => 'C', 'pre_value' => '10', 'post_value' => '20'],
            ['uuid' => $pressureUuid, 'label' => 'Pressure', 'field_type' => 'numeric', 'has_pre_post' => true, 'unit' => 'psi', 'pre_value' => '100', 'post_value' => '120'],
        ]);
        $this->createWorkOrderWithForm([
            ['uuid' => $temperatureUuid, 'label' => 'Temperature', 'field_type' => 'numeric', 'has_pre_post' => true, 'unit' => 'C', 'pre_value' => '20', 'post_value' => '30'],
            ['uuid' => $pressureUuid, 'label' => 'Pressure', 'field_type' => 'numeric', 'has_pre_post' => true, 'unit' => 'psi', 'pre_value' => 'invalid', 'post_value' => '999'],
        ]);

        $summary = $this->actingAs($this->admin)
            ->getJson('/api/reports/form-results')
            ->assertOk()
            ->json('summary');

        $this->assertSame(3, $summary['numeric_pre_post_count']);
        $this->assertCount(2, $summary['numeric_comparisons']);

        $temperature = collect($summary['numeric_comparisons'])->firstWhere('field_uuid', $temperatureUuid);
        $pressure = collect($summary['numeric_comparisons'])->firstWhere('field_uuid', $pressureUuid);

        $this->assertSame('C', $temperature['unit']);
        $this->assertEquals(15.0, $temperature['avg_pre_value']);
        $this->assertEquals(25.0, $temperature['avg_post_value']);
        $this->assertSame(2, $temperature['comparison_count']);
        $this->assertSame('psi', $pressure['unit']);
        $this->assertSame(1, $pressure['comparison_count']);
    }

    public function test_respects_date_window(): void
    {
        $wo1 = $this->createWorkOrderWithForm([
            ['label' => 'Field1', 'field_type' => 'text', 'post_value' => 'value1'],
        ], 'RECENT');
        $wo1->created_at = now()->subDays(10);
        $wo1->save();

        $wo2 = $this->createWorkOrderWithForm([
            ['label' => 'Field2', 'field_type' => 'text', 'post_value' => 'value2'],
        ], 'OLD');
        $wo2->created_at = now()->subDays(100);
        $wo2->save();

        $json = $this->actingAs($this->admin)->getJson('/api/reports/form-results')->json();

        $this->assertSame(1, $json['summary']['total_fields']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/form-results')->json();

        $this->assertSame(0, $json['summary']['total_fields']);
        $this->assertSame([], $json['data']);
    }
}
