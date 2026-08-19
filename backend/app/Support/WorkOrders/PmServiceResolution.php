<?php

namespace App\Support\WorkOrders;

use App\Models\WorkOrderPmMark;

/**
 * What a close decided about preventive service — resolved **once**, then read
 * by everything that needs the answer.
 *
 * Three inputs can name a PM level, and they disagree in ways that matter:
 *
 * | `serviced_pm_assignment_id` | Meaning                                  |
 * |----------------------------|------------------------------------------|
 * | omitted                    | apply whatever was marked during the work |
 * | an integer                 | apply this, overriding any staged mark    |
 * | explicit `null`            | apply nothing — the mark does not stand   |
 *
 * The explicit null exists because "unticked" and "not mentioned" were
 * previously the same request. The close dialog seeds its checkbox from the
 * staged mark, so unticking it omitted the field — and the backend then applied
 * the staged mark anyway. The control did nothing.
 *
 * ⚠️ **Resolve once, read many.** `CloseWorkOrder` uses this in two places: to
 * decide which assignment's baseline to reset, and to decide whether to warn
 * that an asset flagged Need Inspection was closed with no PM recorded. Working
 * it out twice is how those two disagree — a suppressed close would apply
 * nothing while a staged mark still silenced the warning, which is exactly the
 * case the warning exists for.
 */
final class PmServiceResolution
{
    private function __construct(
        /** The assignment to apply, or null when this close applies none. */
        public readonly ?int $assignmentId,
        /** The mark staged during the work, whatever the outcome. */
        public readonly ?WorkOrderPmMark $mark,
        /** 'payload' | 'mark' | 'suppressed' | 'none' — for auditing. */
        public readonly string $source,
    ) {}

    /**
     * @param  bool  $provided  whether the key was present in the request at all
     *                          — `array_key_exists`, never `isset`, since an
     *                          explicit null is the whole point
     */
    public static function resolve(?WorkOrderPmMark $mark, bool $provided, ?int $payloadAssignmentId): self
    {
        if ($payloadAssignmentId !== null) {
            return new self($payloadAssignmentId, $mark, 'payload');
        }

        if ($provided) {
            return new self(null, $mark, 'suppressed');
        }

        return $mark === null
            ? new self(null, null, 'none')
            : new self($mark->asset_pm_assignment_id, $mark, 'mark');
    }

    /**
     * Did this close record that a preventive level was performed?
     *
     * The question the Need Inspection warning asks. False for a suppressed
     * close even when a mark exists — that is the closer saying the level was
     * not performed after all.
     */
    public function accountedFor(): bool
    {
        return $this->assignmentId !== null;
    }

    /** The payload named a different level than the technician marked. */
    public function supersedesMark(): bool
    {
        return $this->source === 'payload'
            && $this->mark !== null
            && $this->mark->asset_pm_assignment_id !== $this->assignmentId;
    }
}
