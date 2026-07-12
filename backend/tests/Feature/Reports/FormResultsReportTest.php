<?php

namespace Tests\Feature\Reports;

use App\Enums\RoleCode;
use App\Models\Asset;
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

        // Create or get a valid fa_subclass_type_code
        $faSubclassCode = \App\Models\FaSubclassTypeCode::firstOrCreate(
            ['fa_subclass_code' => $subclassCode],
            ['type_code' => 'TST', 'description' => 'Test subclass']
        );

        // Check if an active template with this subclass code already exists
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
            $templateField = FormTemplateField::create([
                'form_template_id' => $template->id,
                'uuid' => Str::uuid(),
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
        // Create WOs with form fields
        $this->createWorkOrderWithForm([
            [
                'label' => 'Temperature',
                'field_type' => 'numeric',
                'has_pre_post' => true,
                'unit' => '°C',
                'pre_value' => '25.5',
                'post_value' => '22.0',
            ],
            [
                'label' => 'Status',
                'field_type' => 'boolean',
                'has_pre_post' => false,
                'post_value' => 'true',
            ],
        ]);

        $this->createWorkOrderWithForm([
            [
                'label' => 'Temperature',
                'field_type' => 'numeric',
                'has_pre_post' => true,
                'unit' => '°C',
                'pre_value' => '30.0',
                'post_value' => '24.5',
            ],
        ]);

        $json = $this->actingAs($this->admin)->getJson('/api/reports/form-results')->json();

        $this->assertSame(3, $json['summary']['total_fields']);
        $this->assertCount(2, $json['items']); // Grouped by label: Temperature and Status

        // Check boolean field
        $boolField = collect($json['items'])->firstWhere('label', 'Status');
        $this->assertSame('boolean', $boolField['field_type']);
        $this->assertSame(1, $boolField['true_count']);
        $this->assertSame(0, $boolField['false_count']);

        // Check numeric field with pre/post
        $numField = collect($json['items'])->firstWhere('label', 'Temperature');
        $this->assertSame('numeric', $numField['field_type']);
        $this->assertTrue($numField['has_pre_post']);
        $this->assertSame('°C', $numField['unit']);
        $this->assertSame(2, $numField['response_count']);
    }

    public function test_respects_date_window(): void
    {
        // Recent WO (within 30 days)
        $wo1 = $this->createWorkOrderWithForm([
            ['label' => 'Field1', 'field_type' => 'text', 'post_value' => 'value1'],
        ], 'RECENT');
        $wo1->created_at = now()->subDays(10);
        $wo1->save();

        // Old WO (outside 30 days)
        $wo2 = $this->createWorkOrderWithForm([
            ['label' => 'Field2', 'field_type' => 'text', 'post_value' => 'value2'],
        ], 'OLD');
        $wo2->created_at = now()->subDays(60);
        $wo2->save();

        $json = $this->actingAs($this->admin)->getJson('/api/reports/form-results')->json();

        // Default 30-day window should only include recent WO
        $this->assertSame(1, $json['summary']['total_fields']);
    }

    public function test_empty_state(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/api/reports/form-results')->json();

        $this->assertSame(0, $json['summary']['total_fields']);
        $this->assertSame([], $json['items']);
    }
}
