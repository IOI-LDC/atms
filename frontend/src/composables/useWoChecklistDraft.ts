import { computed, ref } from 'vue'
import api, { ApiError } from '@/lib/api'
import type { WoFormFieldValue } from '@/types'

/** The three writable slots on a checklist row. */
type Slot = 'pre_value' | 'post_value' | 'notes'

const SLOTS: Slot[] = ['pre_value', 'post_value', 'notes']

/** Snapshot of a row's saved values, used to detect edits and to revert. */
interface RowBaseline {
  pre_value: string | null
  post_value: string | null
  notes: string | null
}

/**
 * Outcome of a Save. The write is atomic, so this is all-or-nothing: on failure
 * nothing was persisted and every edit is still in the draft.
 */
export interface ChecklistSaveResult {
  ok: boolean
  savedCount: number
  /** Whole-batch failure message. */
  message?: string
  /** 409 — a field no longer belongs to this form (template synced since open). */
  stale?: boolean
}

const isEmpty = (v: string | null | undefined): boolean => v == null || String(v).trim() === ''

/**
 * Local draft state for the WO Completion checklist.
 *
 * The checklist is edited in a sheet with an explicit Save, so edits are held
 * here and flushed on demand — nothing hits the API while the user types.
 *
 * `save()` writes the whole batch through the atomic bulk endpoint
 * `PATCH /work-orders/{id}/form/fields`, sending only the slots that actually
 * changed. Because the server applies all-or-nothing, a failed save leaves the
 * draft untouched and every edit recoverable — there is no partial-write state
 * for the UI to reconcile.
 */
export function useWoChecklistDraft() {
  const rows = ref<WoFormFieldValue[]>([])
  const baselines = ref<Map<number, RowBaseline>>(new Map())
  const saving = ref(false)

  /** Take a working copy of the server's field values. */
  function open(fields: WoFormFieldValue[]): void {
    rows.value = fields
      .slice()
      .sort((a, b) => a.sort_order - b.sort_order)
      .map((f) => ({ ...f }))
    baselines.value = new Map(
      fields.map((f) => [
        f.id,
        { pre_value: f.pre_value, post_value: f.post_value, notes: f.notes },
      ]),
    )
  }

  function changedSlots(row: WoFormFieldValue): Slot[] {
    const base = baselines.value.get(row.id)
    if (!base) return []
    return SLOTS.filter((slot) => (row[slot] ?? '') !== (base[slot] ?? ''))
  }

  const dirtyRows = computed(() => rows.value.filter((r) => changedSlots(r).length > 0))

  const isDirty = computed(() => dirtyRows.value.length > 0)

  /**
   * Required-field progress computed from the *draft*, so the counter moves as
   * the user types rather than waiting for a save. Mirrors the server's gate:
   * a pre/post row needs both slots, everything else needs the value slot.
   */
  const requiredProgress = computed<{
    total: number
    done: number
    complete: boolean
    missing: { uuid: string; label: string }[]
  }>(() => {
    const required = rows.value.filter((r) => r.is_required)
    const filled = (r: WoFormFieldValue) =>
      r.has_pre_post ? !isEmpty(r.pre_value) && !isEmpty(r.post_value) : !isEmpty(r.post_value)
    const missing = required
      .filter((r) => !filled(r))
      .map((r) => ({ uuid: r.uuid, label: r.label }))
    const done = required.length - missing.length
    return {
      total: required.length,
      done,
      complete: required.length > 0 && missing.length === 0,
      missing,
    }
  })

  function revert(): void {
    for (const row of rows.value) {
      const base = baselines.value.get(row.id)
      if (!base) continue
      row.pre_value = base.pre_value
      row.post_value = base.post_value
      row.notes = base.notes
    }
  }

  /**
   * Persist every changed row in one atomic request. Only changed slots are
   * sent — the endpoint treats an absent key as "keep the stored value", the
   * same semantics as the singular PATCH.
   */
  async function save(workOrderId: number | string): Promise<ChecklistSaveResult> {
    const dirty = dirtyRows.value

    if (dirty.length === 0) {
      return { ok: true, savedCount: 0 }
    }

    const fields = dirty.map((row) => {
      const entry: { id: number } & Partial<Record<Slot, string | null>> = { id: row.id }
      for (const slot of changedSlots(row)) {
        entry[slot] = row[slot]
      }
      return entry
    })

    saving.value = true

    try {
      await api.patch(`/work-orders/${workOrderId}/form/fields`, { fields })

      // The write succeeded in full, so every sent row is now the stored value.
      for (const row of dirty) {
        baselines.value.set(row.id, {
          pre_value: row.pre_value,
          post_value: row.post_value,
          notes: row.notes,
        })
      }

      return { ok: true, savedCount: dirty.length }
    } catch (e) {
      // Nothing was written — baselines stay put so the draft remains dirty and
      // the user's edits survive for a retry.
      const isStale = e instanceof ApiError && e.status === 409

      return {
        ok: false,
        savedCount: 0,
        stale: isStale,
        message: e instanceof ApiError ? e.message : 'Failed to save the checklist.',
      }
    } finally {
      saving.value = false
    }
  }

  return {
    rows,
    saving,
    isDirty,
    dirtyRows,
    requiredProgress,
    open,
    revert,
    save,
  }
}
