<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ([
                ['MWD__APS', 'MWD_APS', 'MWD / APS'],
                ['MWD__VERTEX', 'MWD_VERTEX', 'MWD / VERTEX'],
                ['SUB_FLOW__MWD', 'MWD_SUB_FLOW', 'MWD / SUB FLOW'],
            ] as [$legacyCode, $canonicalCode, $canonicalName]) {
                $this->normalizeCategory($legacyCode, $canonicalCode, $canonicalName);
            }
        });
    }

    /**
     * This migration is intentionally irreversible.
     *
     * Once duplicate category references are merged, their original provenance
     * cannot be reconstructed truthfully. Any reversal must be a forward fix.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }

    private function normalizeCategory(
        string $legacyCode,
        string $canonicalCode,
        string $canonicalName,
    ): void {
        $legacy = DB::table('maintenance_categories')
            ->where('code', $legacyCode)
            ->first(['id']);
        $canonical = DB::table('maintenance_categories')
            ->where('code', $canonicalCode)
            ->first(['id']);

        if ($legacy === null && $canonical === null) {
            return;
        }

        if ($canonical === null) {
            DB::table('maintenance_categories')
                ->where('id', $legacy->id)
                ->update([
                    'code' => $canonicalCode,
                    'name' => $canonicalName,
                    'updated_at' => now(),
                ]);

            return;
        }

        if ($legacy !== null) {
            DB::table('assets')
                ->where('maintenance_category_id', $legacy->id)
                ->update(['maintenance_category_id' => $canonical->id]);
            DB::table('parts')
                ->where('maintenance_category_id', $legacy->id)
                ->update(['maintenance_category_id' => $canonical->id]);
            DB::table('maintenance_categories')
                ->where('id', $legacy->id)
                ->delete();
        }

        DB::table('maintenance_categories')
            ->where('id', $canonical->id)
            ->update([
                'name' => $canonicalName,
                'updated_at' => now(),
            ]);
    }
};
