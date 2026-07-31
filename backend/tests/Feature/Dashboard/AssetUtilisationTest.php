<?php

namespace Tests\Feature\Dashboard;

use App\Enums\AssetDeployment;
use App\Enums\BookingStatus;
use App\Enums\LocationType;
use App\Enums\MaintenanceStatus;
use App\Enums\OperationalStatus;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\AssetMeterReading;
use App\Models\AssetPmAssignment;
use App\Models\Booking;
use App\Models\Location;
use App\Models\PmRule;
use App\Models\Role;
use App\Models\UsageReadingType;
use App\Models\User;
use App\Queries\Dashboard\AssetUtilisationQuery;
use App\Queries\Dashboard\ProgramReadinessQuery;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssetUtilisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function location(string $type, string $name = 'Loc'): Location
    {
        return Location::create(['name' => $name.'-'.uniqid(), 'type' => $type]);
    }

    private function asset(?Location $location, array $attributes = []): Asset
    {
        static $n = 0;
        $n++;

        return Asset::create(array_merge([
            'erp_asset_code' => 'A-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => 'Asset '.$n,
            'is_active' => true,
            'current_location_id' => $location?->id,
        ], $attributes));
    }

    private function utilisation(): array
    {
        return app(AssetUtilisationQuery::class)->handle()['utilisation'];
    }

    private function admin(): User
    {
        $role = Role::where('code', RoleCode::ADMINISTRATOR->value)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function bookAsset(Asset $asset, User $user): void
    {
        Booking::create([
            'asset_id' => $asset->id,
            'booked_by' => $user->id,
            'booked_from' => now()->subDay()->toDateString(),
            'booked_until' => now()->addDays(30)->toDateString(),
            'status' => BookingStatus::ACTIVE,
        ]);
    }

    // ── Classification ───────────────────────────────────────────────────────

    public function test_every_location_type_maps_to_a_deployment_bucket(): void
    {
        foreach (LocationType::cases() as $type) {
            $this->assertNotNull(
                AssetDeployment::forLocationType($type->value),
                "LocationType::{$type->name} has no deployment bucket. Add it to AssetDeployment::forLocationType()."
            );
        }
    }

    public function test_rig_and_well_site_are_the_only_deployed_types(): void
    {
        $deployed = array_filter(
            LocationType::cases(),
            fn (LocationType $t) => AssetDeployment::forLocationType($t->value) === AssetDeployment::DEPLOYED
        );

        $this->assertEqualsCanonicalizing(
            [LocationType::RIG, LocationType::WELL_SITE],
            array_values($deployed)
        );
    }

    public function test_workshop_is_maintenance_not_idle(): void
    {
        $this->assertSame(AssetDeployment::MAINTENANCE, AssetDeployment::forLocationType('workshop'));
        $this->assertSame(AssetDeployment::IDLE, AssetDeployment::forLocationType('yard'));
        $this->assertSame(AssetDeployment::IDLE, AssetDeployment::forLocationType('building'));
    }

    public function test_unknown_location_type_is_not_classified(): void
    {
        $this->assertNull(AssetDeployment::forLocationType('helipad'));
        $this->assertNull(AssetDeployment::forLocationType(null));
    }

    /**
     * Guards against a location type being added to the database without a
     * matching bucket, which would silently distort every utilisation figure.
     */
    public function test_no_location_type_in_the_database_is_unclassified(): void
    {
        $this->location('rig');
        $this->location('yard');
        $this->location('workshop');

        $unclassified = DB::table('locations')
            ->distinct()
            ->pluck('type')
            ->filter(fn ($type) => AssetDeployment::forLocationType($type) === null)
            ->all();

        $this->assertSame([], $unclassified, 'Unclassified location types: '.implode(', ', $unclassified));
    }

    // ── Buckets ──────────────────────────────────────────────────────────────

    public function test_assets_are_bucketed_by_location_type(): void
    {
        $this->asset($this->location('rig'));
        $this->asset($this->location('well_site'));
        $this->asset($this->location('yard'));
        $this->asset($this->location('building'));
        $this->asset($this->location('workshop'));

        $u = $this->utilisation();

        $this->assertSame(2, $u['by_bucket']['deployed']);
        $this->assertSame(2, $u['by_bucket']['idle']);
        $this->assertSame(1, $u['by_bucket']['maintenance']);
        $this->assertSame(5, $u['total']);
        $this->assertSame(40.0, $u['percentage']);
    }

    public function test_unlocated_assets_are_reported_but_excluded_from_the_denominator(): void
    {
        $this->asset($this->location('rig'));
        $this->asset(null);
        $this->asset(null);
        $this->asset(null);

        $u = $this->utilisation();

        $this->assertSame(3, $u['unlocated']);
        $this->assertSame(1, $u['eligible']);
        // 1 of 1 located asset is deployed — the 3 unlocated must not drag this to 25%.
        $this->assertSame(100.0, $u['percentage']);
        $this->assertSame(4, $u['total']);
    }

    public function test_unknown_location_type_is_counted_as_unclassified(): void
    {
        $this->asset($this->location('rig'));
        $this->asset($this->location('helipad'));

        $u = $this->utilisation();

        $this->assertSame(1, $u['unclassified']);
        $this->assertSame(1, $u['eligible']);
        $this->assertSame(0, $u['by_bucket']['idle']);
    }

    public function test_down_and_under_maintenance_assets_leave_the_denominator(): void
    {
        $yard = $this->location('yard');
        $rig = $this->location('rig');

        $this->asset($rig);
        $this->asset($yard, ['operational_status' => OperationalStatus::DOWN->value]);
        $this->asset($yard, ['operational_status' => OperationalStatus::UNDER_MAINTENANCE->value]);

        $u = $this->utilisation();

        // Only the rig asset is eligible, so utilisation is 100% rather than 33.3%.
        $this->assertSame(1, $u['eligible']);
        $this->assertSame(1, $u['deployed_eligible']);
        $this->assertSame(100.0, $u['percentage']);
        // They still appear in the buckets so the bar reflects reality.
        $this->assertSame(2, $u['by_bucket']['idle']);
    }

    public function test_inactive_and_withdrawn_assets_are_outside_the_population(): void
    {
        $rig = $this->location('rig');

        $this->asset($rig);
        $this->asset($rig, ['is_active' => false]);
        $this->asset($rig, ['maintenance_status' => MaintenanceStatus::WITHDRAWN->value]);

        $this->assertSame(1, $this->utilisation()['total']);
    }

    public function test_percentage_is_null_when_nothing_is_eligible(): void
    {
        $this->asset(null);

        $u = $this->utilisation();

        $this->assertNull($u['percentage']);
        $this->assertSame(0, $u['eligible']);
    }

    public function test_booked_count_is_reported_independently_of_location(): void
    {
        $admin = $this->admin();
        $a1 = $this->asset($this->location('rig'));
        $a2 = $this->asset(null);
        $this->asset($this->location('yard'));

        $this->bookAsset($a1, $admin);
        $this->bookAsset($a2, $admin);

        $this->assertSame(2, $this->utilisation()['booked']);
    }

    // ── Readiness ────────────────────────────────────────────────────────────

    public function test_readiness_reports_pm_location_and_reading_coverage(): void
    {
        $rig = $this->location('rig');
        $withEverything = $this->asset($rig);
        $this->asset(null);

        $admin = $this->admin();

        $rule = PmRule::create([
            'name' => 'L1', 'maintenance_level' => 'L1',
            'trigger_type' => 'date', 'interval_days' => 30, 'is_active' => true,
            'created_by' => $admin->id,
        ]);
        AssetPmAssignment::create([
            'asset_id' => $withEverything->id, 'pm_rule_id' => $rule->id, 'is_active' => true,
            'assigned_by' => $admin->id,
        ]);

        $type = UsageReadingType::create(['name' => 'Hours', 'unit' => 'h', 'is_active' => true]);
        AssetMeterReading::create([
            'asset_id' => $withEverything->id,
            'usage_reading_type_id' => $type->id,
            'reading_value' => 100,
            'reading_at' => now(),
            'source' => 'manual',
            'entered_by_user_id' => $admin->id,
        ]);

        $readiness = app(ProgramReadinessQuery::class)->handle()['readiness'];

        $this->assertSame(2, $readiness['total']);
        $this->assertSame(1, $readiness['pm_coverage']['covered']);
        $this->assertSame(50.0, $readiness['pm_coverage']['percentage']);
        $this->assertSame(1, $readiness['location_recorded']['covered']);
        $this->assertSame(1, $readiness['baseline_reading']['covered']);
    }

    public function test_readiness_percentages_are_null_with_no_assets(): void
    {
        $readiness = app(ProgramReadinessQuery::class)->handle()['readiness'];

        $this->assertSame(0, $readiness['total']);
        $this->assertNull($readiness['pm_coverage']['percentage']);
    }

    // ── Endpoint ─────────────────────────────────────────────────────────────

    public function test_kpi_endpoint_exposes_utilisation_readiness_and_status_axes(): void
    {
        $admin = $this->admin();

        $a = $this->asset($this->location('rig'));
        $this->bookAsset($a, $admin);

        $this->actingAs($admin)->getJson('/api/dashboard/kpis')
            ->assertOk()
            ->assertJsonStructure([
                'kpis' => [
                    'utilisation' => [
                        'percentage', 'eligible', 'deployed_eligible',
                        'by_bucket' => ['deployed', 'idle', 'maintenance'],
                        'unlocated', 'unclassified', 'booked', 'total',
                    ],
                    'readiness' => [
                        'total',
                        'pm_coverage' => ['covered', 'percentage'],
                        'location_recorded' => ['covered', 'percentage'],
                        'baseline_reading' => ['covered', 'percentage'],
                    ],
                    'asset_health' => [
                        'by_booking' => ['booked', 'available'],
                    ],
                ],
            ])
            // JSON decodes a whole-number float as int, hence 100 not 100.0.
            ->assertJsonPath('kpis.utilisation.percentage', 100)
            ->assertJsonPath('kpis.asset_health.by_booking.booked', 1);
    }
}
