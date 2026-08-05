<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The asset's meter position at the moment a work order closed.
 *
 * Without this, "how many hours since the last repair" has no data behind it —
 * `work_orders` records who closed a job and when, but never what the meter read.
 * "Since last service" was already answerable from
 * `asset_pm_assignments.last_triggered_reading`; this gives repairs the same.
 *
 * One row per reading type, not one column pair on `work_orders`: three types are
 * live (Operating Hours, Kilometer Driven, Depth) and assets already carry
 * readings for all three, so a single column would capture hours and silently
 * lose the rest for the same job.
 *
 * These are immutable historical facts. They are deliberately *not* recomputed
 * when a reading is later edited or deleted — the snapshot records what the
 * meter was understood to read at close, which is what "since then" must measure
 * against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_meter_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('usage_reading_type_id')->constrained()->cascadeOnDelete();
            $table->decimal('reading_value', 12, 2);
            // The reading_at of the source reading, not the close timestamp: the
            // meter position is as-of when it was read, which may predate close.
            $table->timestamp('reading_at')->nullable();
            $table->timestamps();

            // One snapshot per type per work order — close runs once, and a
            // re-close is impossible (closed is terminal).
            $table->unique(['work_order_id', 'usage_reading_type_id'], 'wo_meter_snapshot_unique');
            $table->index(['usage_reading_type_id', 'work_order_id'], 'wo_meter_snapshot_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_meter_snapshots');
    }
};
