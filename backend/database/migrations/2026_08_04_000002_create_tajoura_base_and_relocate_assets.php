<?php

use App\Models\Location;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MARKER = 'migration:2026-08-04 tajoura-base';

    public function up(): void
    {
        $tjb = Location::firstOrCreate(
            ['code' => 'TJB'],
            ['name' => 'Tajoura Base', 'type' => 'yard', 'description' => 'Tajoura Base — primary asset storage and operational base', 'is_active' => true],
        );

        // Idempotent: if this migration already relocated assets, do nothing.
        if (DB::table('asset_location_histories')->where('notes', self::MARKER)->exists()) {
            return;
        }

        $now = now();
        $history = DB::table('assets')
            ->select('id', 'current_location_id')
            ->get()
            ->map(fn ($asset) => [
                'asset_id' => $asset->id,
                'from_location_id' => $asset->current_location_id,
                'to_location_id' => $tjb->id,
                'effective_at' => $now,
                'reason' => 'bulk relocation',
                'notes' => self::MARKER,
                'changed_by_user_id' => null,
                'created_at' => $now,
            ])
            ->all();

        DB::table('asset_location_histories')->insert($history);
        DB::table('assets')->update(['current_location_id' => $tjb->id]);
    }

    public function down(): void
    {
        $tjb = Location::where('code', 'TJB')->first();
        if (! $tjb) {
            return;
        }

        $rows = DB::table('asset_location_histories')
            ->where('to_location_id', $tjb->id)
            ->where('notes', self::MARKER)
            ->get();

        foreach ($rows as $row) {
            DB::table('assets')->where('id', $row->asset_id)->update(['current_location_id' => $row->from_location_id]);
        }

        // to_location_id FK cascades on delete — dropping TJB removes these history rows.
        $tjb->delete();
    }
};
