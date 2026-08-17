<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RQ1 — a PM level marked as performed *during* a work order.
 *
 * The mark is **staged, not applied**: it records what the team did while they
 * were working, and `CloseWorkOrder` turns it into an actual baseline reset when
 * the work order closes. Cancelling discards it.
 *
 * Applying immediately was the obvious alternative and is the wrong one: it
 * would advance the asset's PM schedule the moment someone ticked a box, so a
 * work order that was later cancelled would leave the next service silently
 * pushed out by a full interval with nothing on the record explaining why.
 *
 * `work_order_id` is UNIQUE — one mark per work order. The ladder is cumulative
 * (L3 ⊇ L2 ⊇ L1), so a second mark could only be redundant or contradictory,
 * and the UI is a single "highest level performed" picker. The constraint is
 * also the idempotency key: re-marking replaces rather than accumulates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_pm_marks', function (Blueprint $table) {
            $table->id();

            // Cascade: the mark is a detail of the work order and has no meaning
            // without it. Nothing else references it.
            $table->foreignId('work_order_id')->unique()->constrained()->cascadeOnDelete();

            $table->foreignId('asset_pm_assignment_id')->constrained()->cascadeOnDelete();

            // Nullable so a mark survives the person who made it being removed —
            // the fact that a service happened outlives the account.
            $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('marked_at');
            $table->timestamps();

            $table->index('asset_pm_assignment_id', 'wo_pm_marks_assignment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_pm_marks');
    }
};
