<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Release 4a — seed the `asset_conditions` vocabulary and backfill assets.
 *
 * Three of these four values are LDC's own words, taken from their status
 * requests: Need Assembly, Missing Parts, Need Inspection. `normal` is the
 * fourth ATMS needs and LDC did not name — the "nothing wrong" default every
 * asset carries and the value a work-order close resets to (confirmed with the
 * user 2026-08-16).
 *
 * `normal` is seeded first and carries `is_default`, so it heads the picker and
 * is what `CloseWorkOrder` resolves. "Need Maintenance" is deliberately absent:
 * a pending Maintenance Request already records that need, and duplicating it as
 * a hand-set label would let the two disagree.
 *
 * Idempotent — `updateOrInsert` on (group_key, value), which is the table's
 * unique key. Re-running changes nothing.
 */
return new class extends Migration
{
    private const GROUP = 'asset_conditions';

    private const DEFAULT_VALUE = 'normal';

    /** @var list<array{value: string, label: string, is_default: bool}> */
    private const VALUES = [
        ['value' => 'normal', 'label' => 'Normal', 'is_default' => true],
        ['value' => 'need_assembly', 'label' => 'Need Assembly', 'is_default' => false],
        ['value' => 'missing_parts', 'label' => 'Missing Parts', 'is_default' => false],
        ['value' => 'need_inspection', 'label' => 'Need Inspection', 'is_default' => false],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::VALUES as $i => $row) {
            DB::table('master_data_items')->updateOrInsert(
                ['group_key' => self::GROUP, 'value' => $row['value']],
                [
                    'label' => $row['label'],
                    'sort_order' => $i,
                    'is_active' => true,
                    'is_default' => $row['is_default'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        // Existing assets have no condition yet, and "unknown" is not a state
        // this vocabulary has — every asset reads as Normal until someone says
        // otherwise. Scoped to NULL so a re-run cannot flatten real values.
        DB::table('assets')
            ->whereNull('condition_status')
            ->update(['condition_status' => self::DEFAULT_VALUE]);
    }

    public function down(): void
    {
        // Clear only what this migration set. A condition someone has since
        // chosen deliberately is left alone — the column drop in 4c is the
        // irreversible step, not this one.
        DB::table('assets')
            ->where('condition_status', self::DEFAULT_VALUE)
            ->update(['condition_status' => null]);

        DB::table('master_data_items')->where('group_key', self::GROUP)->delete();
    }
};
