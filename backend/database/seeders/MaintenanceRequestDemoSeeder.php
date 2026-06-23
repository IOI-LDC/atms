<?php

namespace Database\Seeders;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\RoleCode;
use App\Models\Asset;
use App\Models\BusinessNumberSequence;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * TEMPORARY demo seeder — populates enough data to exercise the DataTable
 * (My Requests / Pending Approval / All Requests tabs) and the MR detail page
 * (Edit, Approve, Reject, Cancel) end-to-end.
 *
 * Creates: 12 demo assets, 5 demo users (1 manager + 4 requesters), and 200
 * maintenance requests spread across status / priority / type / creator / asset
 * with created_at spanning ~18 months (for sort testing).
 *
 * Run:
 *   docker exec atms-api php artisan db:seed --class=MaintenanceRequestDemoSeeder
 *
 * This seeder is NOT registered in DatabaseSeeder, so a normal migrate-fresh
 * + seed will never run it.
 */
class MaintenanceRequestDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (MaintenanceRequest::count() >= 200) {
            $this->command->warn(
                'maintenance_requests already has '.MaintenanceRequest::count()
                .' rows. Aborting to avoid duplicates — delete them first if you want a fresh demo.'
            );
            return;
        }

        $admin = User::where('email', 'admin@atms.local')->firstOrFail();

        // BusinessNumberSequence::next() calls firstOrFail() on this row.
        BusinessNumberSequence::firstOrCreate(['type' => 'MR'], ['current_value' => 0]);

        // ── Support data: assets ──────────────────────────────────────────────
        $assetNames = [
            'Caterpillar 320 Excavator', 'Komatsu D65 Bulldozer', 'Volvo L120 Wheel Loader',
            'Liebherr LR1300 Crane', 'JCB 3CX Backhoe', 'Hitachi ZX350 Excavator',
            'Manitou MT1840 Telehandler', 'Sany SY215 Excavator', 'Doosan DX140 Excavator',
            'Bobcat S650 Skid Steer', 'Terex TR100 Dump Truck', 'Atlas Copco XAS185 Compressor',
        ];
        $assets = collect();
        foreach ($assetNames as $i => $name) {
            $assets->push(Asset::firstOrCreate(
                ['erp_asset_code' => 'DEMO-AST-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT)],
                [
                    'name' => $name,
                    'category' => 'Demo Equipment',
                    'operational_status' => 'active',
                    'erp_status' => 'active',
                    'is_active' => true,
                ],
            ));
        }

        // ── Support data: demo users (manager + requesters) ───────────────────
        $managerRole = Role::where('code', RoleCode::MAINTENANCE_MANAGER->value)->first();
        $requesterRole = Role::where('code', RoleCode::REQUESTER->value)->first();

        $demoUsers = collect([
            User::firstOrCreate(
                ['email' => 'demo.manager@atms.local'],
                [
                    'name' => 'Demo Manager',
                    'password' => bcrypt('password'),
                    'role_id' => $managerRole?->id,
                    'is_active' => true,
                    'activated_at' => now(),
                    'email_verified_at' => now(),
                ],
            ),
        ]);
        foreach (['Alice', 'Bilal', 'Chen', 'Dora'] as $first) {
            $demoUsers->push(User::firstOrCreate(
                ['email' => 'demo.'.strtolower($first).'@atms.local'],
                [
                    'name' => "Demo $first",
                    'password' => bcrypt('password'),
                    'role_id' => $requesterRole?->id,
                    'is_active' => true,
                    'activated_at' => now(),
                    'email_verified_at' => now(),
                ],
            ));
        }

        // Admin is a creator too, so its "My Requests" tab is populated.
        $creators = $demoUsers->concat([$admin]);

        // ── Distribution pools (shuffled, so rows look natural) ───────────────
        $statuses = collect()
            ->merge(array_fill(0, 90, MaintenanceRequestStatus::PENDING_REVIEW)) // 45%
            ->merge(array_fill(0, 50, MaintenanceRequestStatus::CONVERTED))      // 25%
            ->merge(array_fill(0, 30, MaintenanceRequestStatus::REJECTED))       // 15%
            ->merge(array_fill(0, 30, MaintenanceRequestStatus::CANCELLED))      // 15%
            ->shuffle()->values();

        $priorities = collect(['low', 'medium', 'high', 'critical'])
            ->flatMap(fn ($p) => array_fill(0, 50, $p))
            ->shuffle()->values();

        $types = collect(array_fill(0, 170, 'corrective'))
            ->merge(array_fill(0, 30, 'preventive'))
            ->shuffle()->values();

        $startId = (int) (MaintenanceRequest::max('id') ?? 0);

        DB::transaction(function () use ($statuses, $priorities, $types, $creators, $assets, $admin) {
            $statuses->each(function (MaintenanceRequestStatus $status, int $i) use ($priorities, $types, $creators, $assets, $admin) {
                // Bias the first 50 to the admin so "My Requests" has content.
                $creator = $i < 50 ? $admin : $creators[$i % $creators->count()];
                $asset = $assets[$i % $assets->count()];
                $type = $types[$i];
                $createdAt = now()->subDays(rand(0, 540))->subHours(rand(0, 23));

                $attrs = [
                    'number' => BusinessNumberSequence::next('MR', 'MR-'),
                    'asset_id' => $asset->id,
                    'type' => $type,
                    'status' => $status,
                    'priority' => $priorities[$i],
                    'description' => fake()->sentence(rand(6, 14)),
                    'created_by' => $creator->id,
                    'is_preventive' => $type === 'preventive',
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $attrs = match ($status) {
                    MaintenanceRequestStatus::REJECTED => $attrs + [
                        'rejection_reason' => fake()->sentence(),
                        'reviewed_by' => $admin->id,
                        'reviewed_at' => $createdAt->copy()->addDays(rand(1, 5)),
                    ],
                    MaintenanceRequestStatus::CONVERTED => $attrs + [
                        'reviewed_by' => $admin->id,
                        'reviewed_at' => $createdAt->copy()->addDays(rand(1, 3)),
                    ],
                    MaintenanceRequestStatus::CANCELLED => $attrs + [
                        'cancellation_reason' => fake()->sentence(),
                        'cancelled_by' => $creator->id,
                        'cancelled_at' => $createdAt->copy()->addDays(rand(0, 4)),
                    ],
                    default => $attrs,
                };

                // forceFill bypasses $fillable so we can set created_at/updated_at.
                (new MaintenanceRequest())->forceFill($attrs)->save();
            });
        });

        $endId = (int) MaintenanceRequest::max('id');

        $this->command->info("Created 200 maintenance requests (id {$startId}–{$endId}).");
        $this->command->info('Demo logins (password: password): admin@atms.local, demo.manager@atms.local, demo.alice@atms.local');
        $this->command->warn("Rollback: docker exec atms-api php artisan tinker --execute=\"\\App\\Models\\MaintenanceRequest::whereBetween('id',[{$startId},{$endId}])->delete(); \\App\\Models\\User::where('email','like','demo.%')->delete(); \\App\\Models\\Asset::where('erp_asset_code','like','DEMO-AST-%')->delete();\"");
    }
}
