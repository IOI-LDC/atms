<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { useWoChecklistDraft } from '@/composables/useWoChecklistDraft'
import type { WoFormFieldValue } from '@/types'

const props = defineProps<{
  open: boolean
  workOrderId: number | string
  fields: WoFormFieldValue[]
  canEdit: boolean
  /** uuids flagged by the last failed completion attempt (422 gate). */
  missingFields: Set<string>
}>()

const emit = defineEmits<{
  close: []
  saved: []
}>()

// `open` is aliased — the bare name would shadow the `open` prop in the template.
const {
  rows,
  saving,
  isDirty,
  requiredProgress,
  open: snapshotDraft,
  revert,
  save,
} = useWoChecklistDraft()

// Snapshot the server values each time the sheet is opened, so a re-open after
// an external change (form sync, reload) starts from current data.
watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) snapshotDraft(props.fields)
  },
  { immediate: true },
)

/**
 * Boolean slots are an explicit Yes/No choice, not a Switch.
 *
 * The server treats `null` as unanswered and `'0'` as a recorded "No"
 * (WorkOrder::isEmptyValue), but a two-state Switch renders both as "off" — so
 * an untouched required boolean looked answered while still blocking Complete.
 * With Yes/No, "nothing selected" is visibly distinct from "No".
 *
 * A click on the already-selected item would emit an empty value (reka-ui
 * deselect); that is ignored so a recorded answer can't silently revert to
 * unanswered.
 */
function setBooleanChoice(row: WoFormFieldValue, slot: 'pre_value' | 'post_value', value: unknown) {
  if (value === '1' || value === '0') row[slot] = value
}

/** uuids of required rows still unfilled *in the draft*, recomputed as you type. */
const unfilledUuids = computed(() => new Set(requiredProgress.value.missing.map((m) => m.uuid)))

/**
 * A row is flagged when the completion gate named it and it is still unfilled.
 * Gating on the live draft means a row clears itself the moment the user fills
 * it, rather than staying red until the next Complete attempt.
 */
function rowFlagged(row: WoFormFieldValue): boolean {
  return props.missingFields.has(row.uuid) && unfilledUuids.value.has(row.uuid)
}

// ── Save ──────────────────────────────────────────────────────────────────────
async function doSave() {
  const result = await save(props.workOrderId)

  if (result.ok) {
    toast.success(result.savedCount === 1 ? '1 field saved.' : `${result.savedCount} fields saved.`)
    emit('saved')
    emit('close')
    return
  }

  // The write is atomic, so nothing was persisted and every edit is still in
  // the draft — keep the sheet open so the user can retry without retyping.
  toast.error(
    result.stale
      ? 'This checklist changed since you opened it. Close and reopen it, then re-enter your changes.'
      : (result.message ?? 'Failed to save the checklist.'),
  )
}

// ── Discard confirmation ──────────────────────────────────────────────────────
const discardOpen = ref(false)

function requestClose() {
  if (isDirty.value) {
    discardOpen.value = true
    return
  }
  emit('close')
}

function confirmDiscard() {
  revert()
  discardOpen.value = false
  emit('close')
}
</script>

<template>
  <!-- :modal="false" matches every other sheet in the app, and is required here:
       the discard-confirmation Dialog below would otherwise fight the sheet's
       focus trap. Dismissing by clicking outside still routes through
       requestClose(), so the unsaved-changes guard holds. -->
  <Sheet :open="open" :modal="false" @update:open="(v) => !v && requestClose()">
    <SheetContent side="right" class="create-sheet">
      <div class="create-sheet-header">
        <SheetHeader>
          <SheetTitle>Completion checklist</SheetTitle>
          <SheetDescription>
            {{
              canEdit
                ? 'Record pre and post values for each field. Changes are saved when you press Save.'
                : 'This checklist is read-only — the work order is completed or not assigned to you.'
            }}
          </SheetDescription>
        </SheetHeader>
      </div>

      <div class="create-sheet-body">
        <div v-if="rows.length === 0" class="empty-state">This form has no fields.</div>

        <div v-else class="wo-form-table-scroll">
          <table class="detail-table wo-form-table">
            <colgroup>
              <col class="wo-form-col-field" />
              <col class="wo-form-col-slot" />
              <col class="wo-form-col-slot" />
              <col class="wo-form-col-notes" />
            </colgroup>
            <thead class="detail-table-head">
              <tr>
                <th>Field</th>
                <th>Pre</th>
                <th>Post / Value</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in rows"
                :key="row.uuid"
                class="detail-table-row"
                :class="{ 'wo-form-row-missing': rowFlagged(row) }"
              >
                <td class="detail-table-cell wo-form-field-cell">
                  <span class="wo-form-field-name">{{ row.label }}</span>
                  <span v-if="row.is_required" class="field-required">*</span>
                  <span v-if="row.unit" class="detail-field-muted"> ({{ row.unit }})</span>
                </td>

                <td class="detail-table-cell" data-label="Pre">
                  <template v-if="row.has_pre_post">
                    <ToggleGroup
                      v-if="row.field_type === 'boolean'"
                      type="single"
                      size="sm"
                      variant="outline"
                      :model-value="row.pre_value ?? ''"
                      :disabled="!canEdit"
                      class="wo-form-boolean-choice"
                      @update:model-value="(v) => setBooleanChoice(row, 'pre_value', v)"
                    >
                      <ToggleGroupItem value="1" :aria-label="`${row.label} — pre: Yes`">
                        Yes
                      </ToggleGroupItem>
                      <ToggleGroupItem value="0" :aria-label="`${row.label} — pre: No`">
                        No
                      </ToggleGroupItem>
                    </ToggleGroup>
                    <Input
                      v-else
                      :model-value="row.pre_value ?? ''"
                      :type="row.field_type === 'numeric' ? 'number' : 'text'"
                      :disabled="!canEdit"
                      :aria-label="`${row.label} — pre`"
                      @update:model-value="(v) => (row.pre_value = String(v))"
                    />
                  </template>
                  <span v-else class="detail-table-remove">—</span>
                </td>

                <td
                  class="detail-table-cell"
                  :data-label="row.has_pre_post ? 'Post / Value' : 'Value'"
                >
                  <ToggleGroup
                    v-if="row.field_type === 'boolean'"
                    type="single"
                    size="sm"
                    variant="outline"
                    :model-value="row.post_value ?? ''"
                    :disabled="!canEdit"
                    class="wo-form-boolean-choice"
                    @update:model-value="(v) => setBooleanChoice(row, 'post_value', v)"
                  >
                    <ToggleGroupItem
                      value="1"
                      :aria-label="`${row.has_pre_post ? `${row.label} — post` : row.label}: Yes`"
                    >
                      Yes
                    </ToggleGroupItem>
                    <ToggleGroupItem
                      value="0"
                      :aria-label="`${row.has_pre_post ? `${row.label} — post` : row.label}: No`"
                    >
                      No
                    </ToggleGroupItem>
                  </ToggleGroup>
                  <Input
                    v-else
                    :model-value="row.post_value ?? ''"
                    :type="row.field_type === 'numeric' ? 'number' : 'text'"
                    :disabled="!canEdit"
                    :aria-label="row.has_pre_post ? `${row.label} — post` : row.label"
                    @update:model-value="(v) => (row.post_value = String(v))"
                  />
                </td>

                <td class="detail-table-cell" data-label="Notes">
                  <Input
                    :model-value="row.notes ?? ''"
                    :disabled="!canEdit"
                    placeholder="Add note…"
                    :aria-label="`${row.label} — notes`"
                    @update:model-value="(v) => (row.notes = String(v))"
                  />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="create-sheet-footer">
        <span
          v-if="requiredProgress.total > 0"
          class="wo-checklist-footer-status"
          :data-complete="requiredProgress.complete"
        >
          {{ requiredProgress.done }} / {{ requiredProgress.total }} required complete
        </span>
        <Button variant="outline" :disabled="saving" @click="requestClose">
          {{ canEdit ? 'Cancel' : 'Close' }}
        </Button>
        <Button v-if="canEdit" :disabled="!isDirty || saving" @click="doSave">
          {{ saving ? 'Saving…' : 'Save' }}
        </Button>
      </div>
    </SheetContent>
  </Sheet>

  <!-- Discard unsaved edits -->
  <Dialog v-model:open="discardOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Discard changes?</DialogTitle>
        <DialogDescription>
          Your unsaved checklist edits will be lost. Fields already saved are not affected.
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" @click="discardOpen = false">Keep editing</Button>
        <Button variant="destructive" @click="confirmDiscard">Discard</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
