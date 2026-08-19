<?php

namespace Tests\Feature\Migrations;

use App\Enums\RoleCode;
use App\Models\MaintenanceCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Release 4b — the value migration that empties the legacy vocabulary.
 *
 * Everything here is written against raw `DB` rather than Eloquent on purpose:
 * the enum has already narrowed to four cases, so `Asset` cannot even *read* a
 * row carrying a legacy value. That is precisely the failure this migration
 * exists to prevent, and it is why the fixtures below are raw inserts.
 */
class OperationalStatusValueMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require collect(glob(database_path('migrations/*_migrate_operational_status_values.php')))->sole();
    }

    private function asset(string $status, array $overrides = []): int
    {
        return DB::table('assets')->insertGetId(array_merge([
            'erp_asset_code' => 'AST-4B-'.uniqid(),
            'name' => 'Migration Fixture',
            'maintenance_category_id' => MaintenanceCategory::factory()->create()->id,
            'is_active' => true,
            'operational_status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function statusOf(int $id): string
    {
        return DB::table('assets')->where('id', $id)->value('operational_status');
    }

    // ── The mapping ─────────────────────────────────────────────────────────────

    public function test_down_becomes_failure_and_stays_in_the_fleet(): void
    {
        $id = $this->asset('down');

        $this->migration()->up();

        $this->assertSame('failure', $this->statusOf($id));
        $this->assertTrue((bool) DB::table('assets')->where('id', $id)->value('is_active'));
    }

    public function test_under_inspection_becomes_under_maintenance(): void
    {
        $id = $this->asset('under_inspection');

        $this->migration()->up();

        $this->assertSame('under_maintenance', $this->statusOf($id));
        $this->assertTrue((bool) DB::table('assets')->where('id', $id)->value('is_active'));
    }

    /**
     * `scraped` and `lih` both meant the asset had left the fleet. The agreed
     * vocabulary has no operational value for that, so they become an ordinary
     * status plus a deactivation — `is_active` is the only "out of ATMS"
     * control since 2026-08-16.
     *
     * @return array<string, array{string}>
     */
    public static function retiredStatusProvider(): array
    {
        return ['scrapped' => ['scraped'], 'lost in hole' => ['lih']];
    }

    #[DataProvider('retiredStatusProvider')]
    public function test_a_retired_status_becomes_ready_for_field_and_deactivated(string $legacy): void
    {
        $id = $this->asset($legacy);

        $this->migration()->up();

        $this->assertSame('ready_for_field', $this->statusOf($id));
        $this->assertFalse((bool) DB::table('assets')->where('id', $id)->value('is_active'));
    }

    public function test_current_values_are_left_alone(): void
    {
        $ready = $this->asset('ready_for_field');
        $maintenance = $this->asset('under_maintenance');

        $this->migration()->up();

        $this->assertSame('ready_for_field', $this->statusOf($ready));
        $this->assertSame('under_maintenance', $this->statusOf($maintenance));
    }

    // ── The Eloquent-bypass hazard ──────────────────────────────────────────────

    /**
     * The migration writes raw SQL, so `Asset::booted` — which releases an
     * asset's active bookings when it is deactivated — never fires. The
     * migration therefore performs the release itself.
     *
     * Without this, a scrapped asset would come out of the cutover deactivated
     * but still holding a live booking, which no screen would ever let anyone
     * clear: every booking action goes through the asset, and the asset is now
     * ineligible.
     */
    public function test_deactivating_an_asset_releases_its_active_bookings(): void
    {
        $id = $this->asset('scraped');
        $user = $this->createAdmin();

        $bookingId = DB::table('bookings')->insertGetId([
            'asset_id' => $id,
            'booked_by' => $user->id,
            'booked_from' => now()->toDateString(),
            'booked_until' => now()->addDays(10)->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $booking = DB::table('bookings')->where('id', $bookingId)->first();
        $this->assertSame('released', $booking->status);
        $this->assertNotNull($booking->cancelled_at);
    }

    public function test_a_renamed_asset_keeps_its_bookings(): void
    {
        $id = $this->asset('down');
        $user = $this->createAdmin();

        $bookingId = DB::table('bookings')->insertGetId([
            'asset_id' => $id,
            'booked_by' => $user->id,
            'booked_from' => now()->toDateString(),
            'booked_until' => now()->addDays(10)->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        // A rename does not retire anything, so the booking stands.
        $this->assertSame('active', DB::table('bookings')->where('id', $bookingId)->value('status'));
    }

    // ── Safety properties ───────────────────────────────────────────────────────

    /**
     * The property the whole release rests on: after this runs, no row carries a
     * value the narrowed enum cannot represent.
     *
     * The migration also asserts this itself and throws if it fails — see
     * {@see self::test_an_unmapped_value_aborts_the_migration()} for the case
     * that guard actually catches.
     */
    public function test_no_legacy_value_survives_a_mixed_fleet(): void
    {
        foreach (['down', 'scraped', 'under_inspection', 'lih', 'ready_for_field', 'under_maintenance'] as $status) {
            $this->asset($status);
        }

        $this->migration()->up();

        $this->assertSame(
            0,
            DB::table('assets')->whereIn('operational_status', ['down', 'scraped', 'under_inspection', 'lih'])->count(),
        );
        $this->assertSame(
            ['at_the_field', 'failure', 'ready_for_field', 'under_maintenance'],
            DB::table('assets')->distinct()->pluck('operational_status')->push('at_the_field')->unique()->sort()->values()->all(),
            'Every surviving value is one the narrowed enum defines.',
        );
    }

    /**
     * The guard asserts the **complement**, not the mapped set.
     *
     * An earlier version counted rows still carrying one of the four legacy
     * values — which, after mapping all four, is necessarily zero. It could
     * therefore never fail, and a database carrying anything the mapping had
     * never heard of sailed through and threw on the next Eloquent read instead.
     *
     * `active` is the realistic instance: it was the column default from
     * `create_assets_table` until 2026-08-04, so a row untouched since then
     * still carries it.
     *
     * @return array<string, array{string}>
     */
    public static function unmappedValueProvider(): array
    {
        return [
            'the original column default' => ['active'],
            'a value from some future import' => ['in_transit'],
            'a typo written straight to the column' => ['read_for_field'],
        ];
    }

    #[DataProvider('unmappedValueProvider')]
    public function test_an_unmapped_value_aborts_the_migration(string $status): void
    {
        $legacy = $this->asset('down');
        $this->asset($status);

        try {
            $this->migration()->up();
            $this->fail("Expected the migration to refuse to complete with a [{$status}] row present.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($status, $e->getMessage());
            $this->assertStringContainsString('Refusing to complete', $e->getMessage());
        }

        // The abort is a hard stop, not a rollback: migrations run outside a
        // transaction here, so the mapped rows are already rewritten. That is
        // the right trade — the operator fixes the offending rows and re-runs,
        // and the second pass is a no-op over the ones already done.
        $this->assertSame('failure', $this->statusOf($legacy));
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $down = $this->asset('down');
        $scraped = $this->asset('scraped');

        $this->migration()->up();
        $firstPass = DB::table('assets')->orderBy('id')->pluck('operational_status', 'id')->all();

        $this->migration()->up();

        $this->assertSame($firstPass, DB::table('assets')->orderBy('id')->pluck('operational_status', 'id')->all());
        $this->assertSame('failure', $this->statusOf($down));
        $this->assertFalse((bool) DB::table('assets')->where('id', $scraped)->value('is_active'));
    }

    /**
     * `down()` reverses the rename and deliberately leaves the deactivations
     * alone — `ready_for_field` + inactive is indistinguishable from an asset
     * that was legitimately deactivated while ready, and reactivating on that
     * guess would put retired equipment back in the pool. The cutover's abort
     * path is the backup snapshot, which is why this is acceptable.
     */
    public function test_down_reverses_the_rename_but_not_the_retirement(): void
    {
        $down = $this->asset('down');
        $scraped = $this->asset('scraped');
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertSame('down', $this->statusOf($down));

        $this->assertSame('ready_for_field', $this->statusOf($scraped));
        $this->assertFalse(
            (bool) DB::table('assets')->where('id', $scraped)->value('is_active'),
            'A retired asset stays retired through a rollback — see the migration docblock.',
        );
    }

    private function createAdmin(): User
    {
        $this->seed(RoleSeeder::class);

        return User::factory()->create([
            'role_id' => Role::where('code', RoleCode::ADMINISTRATOR->value)->first()->id,
            'is_active' => true,
        ]);
    }
}
