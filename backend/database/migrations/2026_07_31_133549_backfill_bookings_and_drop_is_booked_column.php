<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill: convert existing is_booked = true assets into booking rows.
        // Use the first admin user as booked_by (system migration, no real user context).
        $adminRoleId = DB::table('roles')->where('code', 'administrator')->value('id');
        $adminId = $adminRoleId
            ? DB::table('users')->where('role_id', $adminRoleId)->where('is_active', true)->value('id')
            : null;
        $today = now()->toDateString();

        if ($adminId) {
            $bookedAssets = DB::table('assets')
                ->where('is_booked', true)
                ->pluck('id');

            foreach ($bookedAssets as $assetId) {
                DB::table('bookings')->insert([
                    'asset_id' => $assetId,
                    'booked_by' => $adminId,
                    'booked_from' => $today,
                    'booked_until' => $today,
                    'booking_reference' => null,
                    'notes' => 'Migrated from legacy is_booked flag.',
                    'status' => 'active',
                    'cancelled_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Drop the legacy column.
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('is_booked');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->boolean('is_booked')->default(false)->after('is_active');
        });

        // Restore: mark assets with active bookings covering today as is_booked.
        $today = now()->toDateString();
        $bookedAssetIds = DB::table('bookings')
            ->where('status', 'active')
            ->where('booked_from', '<=', $today)
            ->where('booked_until', '>=', $today)
            ->pluck('asset_id');

        DB::table('assets')->whereIn('id', $bookedAssetIds)->update(['is_booked' => true]);
    }
};
