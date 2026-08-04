<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import {
  ArrowLeftIcon,
  PaperclipIcon,
  UserPlusIcon,
  UserPenIcon,
  EyeIcon,
  Trash2Icon,
  TriangleAlert,
  ChevronRightIcon,
} from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import AssetIdentity from '@/components/app/AssetIdentity.vue'
import PartIdentity from '@/components/app/PartIdentity.vue'
import DetailNotFound from '@/components/app/DetailNotFound.vue'
import PartCombobox from '@/components/app/PartCombobox.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { DatePicker } from '@/components/ui/date-picker'
import { Checkbox } from '@/components/ui/checkbox'
import { Progress } from '@/components/ui/progress'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { FileInput } from '@/components/ui/file-input'
import WoChecklistSheet from '@/components/work-orders/WoChecklistSheet.vue'
import WoReadingsTable from '@/components/work-orders/WoReadingsTable.vue'
import { useWorkOrderDetail } from '@/composables/useWorkOrderDetail'
import { openAttachmentInNewTab } from '@/lib/attachments'
import {
  woStatusClass,
  woStatusLabel,
  priorityClass,
  priorityLabel,
  failureClass,
  failureLabel,
  operationalStatusLabel,
  operationalStatusClass,
  locationTypeLabel,
  locationTypeClass,
  fmtDate,
  formatBytes,
  roleLabel,
} from '@/lib/displayHelpers'

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.workOrderId))

function goBack() {
  router.back()
}

const {
  record,
  loading,
  error,
  notFound,
  forbidden,
  attachments,
  attachmentsLoading,
  isTerminal,
  isCancelled,
  isCorrectiveOrigin,
  originTypeLabel,
  lifecycleSteps,
  requiredFieldStatus,
  canEdit,
  canAssign,
  canStart,
  canComplete,
  canClose,
  canCancel,
  canSetAssetStatus,
  canEditWoForm,
  editing,
  saving,
  editError,
  draft,
  validationErrors,
  startEdit,
  cancelEdit,
  saveEdit,
  assignOpen,
  assignLoading,
  technicians,
  techniciansLoading,
  selectedTechId,
  openAssign,
  doAssign,
  startLoading,
  startOpen,
  startLocationId,
  startLocationRequired,
  workLocationGroups,
  locationsLoading,
  openStart,
  doStart,
  completeOpen,
  completeLoading,
  completionNotes,
  openComplete,
  doComplete,
  closeOpen,
  closeLoading,
  closeIsFailure,
  closeAssetStatus,
  openClose,
  doClose,
  cancelOpen,
  cancelLoading,
  cancelReason,
  cancelAssetStatus,
  openCancel,
  doCancel,
  addPartOpen,
  addPartLoading,
  partDraft,
  selectedPart,
  openAddPart,
  doAddPart,
  removeTarget,
  removeLoading,
  openRemovePart,
  doRemovePart,
  parts,
  readingTypes,
  recordReadingOpen,
  readingLoading,
  readingDraft,
  workOrderReadings,
  historyReadings,
  readingHistoryOpen,
  readingsLoading,
  lastReadingForDraft,
  readingBelowLast,
  lowerReadingAcknowledged,
  sinceLastService,
  openRecordReading,
  doRecordReading,
  loadAssetReadings,
  canManageReadings,
  editReadingOpen,
  editReadingLoading,
  editReadingDraft,
  openEditReading,
  doEditReading,
  deleteReadingTarget,
  deleteReadingLoading,
  openDeleteReading,
  doDeleteReading,
  assetStatusOpen,
  assetStatusLoading,
  selectedStatus,
  openSetAssetStatus,
  doSetAssetStatus,
  uploadOpen,
  uploadLoading,
  uploadFiles,
  openUpload,
  addFiles,
  removeFile,
  doUpload,
  deleteAttachmentTarget,
  deleteAttachmentLoading,
  openDeleteAttachment,
  doDeleteAttachment,
  syncDeferred,
  missingFields,
  syncForm,
  deferFormSync,
  load,
} = useWorkOrderDetail()

// FileInput is a view-managed primitive — its `open()` method is exposed via ref.
const fileInputRef = ref<InstanceType<typeof FileInput> | null>(null)

// shadcn-vue Select emits string values; the composable holds numeric IDs.
// These wrappers translate between the two for v-model binding.
const selectedTechIdStr = computed({
  get: () => (selectedTechId.value !== null ? String(selectedTechId.value) : undefined),
  set: (v: string | undefined) => {
    selectedTechId.value = v ? Number(v) : null
  },
})
const readingTypeIdStr = computed({
  get: () => (readingDraft.value.typeId !== null ? String(readingDraft.value.typeId) : undefined),
  set: (v: string | undefined) => {
    readingDraft.value.typeId = v ? Number(v) : null
  },
})
// Start-dialog location select round-trips through a string (mirrors readingTypeIdStr).
const startLocationIdStr = computed({
  get: () => (startLocationId.value !== null ? String(startLocationId.value) : undefined),
  set: (v: string | undefined) => {
    startLocationId.value = v ? Number(v) : null
  },
})
const selectedStatusStr = computed({
  get: () => selectedStatus.value ?? undefined,
  set: (v: string | undefined) => {
    selectedStatus.value = v ?? null
  },
})
// Cancel: required asset-status choice (down = still faulty, ready_for_field = false alarm).
const cancelAssetStatusStr = computed({
  get: () => cancelAssetStatus.value ?? undefined,
  set: (v: string | undefined) => {
    cancelAssetStatus.value = v === 'down' || v === 'ready_for_field' ? v : null
  },
})
// Close: failure re-classification (corrective-origin WOs only). boolean|null <-> string.
const closeIsFailureStr = computed<string | undefined>({
  get: () =>
    closeIsFailure.value === null ? undefined : closeIsFailure.value ? 'failure' : 'no_failure',
  set: (v: string | undefined) => {
    closeIsFailure.value = v === 'failure' ? true : v === 'no_failure' ? false : null
  },
})

// Numeric/nullable inputs must round-trip through strings for shadcn Input.
const readingValueStr = computed({
  get: () => (readingDraft.value.value !== null ? String(readingDraft.value.value) : ''),
  set: (v: string) => {
    readingDraft.value.value = v === '' ? null : Number(v)
  },
})
const partQuantityStr = computed({
  get: () => String(partDraft.value.quantity),
  set: (v: string) => {
    partDraft.value.quantity = v === '' ? 0 : Number(v)
  },
})

// Part removal uses a target id (not a separate open flag) as its open state.
const removeOpen = computed({
  get: () => removeTarget.value !== null,
  set: (open: boolean) => {
    if (!open) removeTarget.value = null
  },
})

// Attachment deletion uses its target id as open state (same pattern as parts).
const deleteAttachmentOpen = computed({
  get: () => deleteAttachmentTarget.value !== null,
  set: (open: boolean) => {
    if (!open) deleteAttachmentTarget.value = null
  },
})

// Edit-reading numeric input round-trips through a string (mirrors readingValueStr).
const editReadingValueStr = computed({
  get: () => (editReadingDraft.value.value !== null ? String(editReadingDraft.value.value) : ''),
  set: (v: string) => {
    editReadingDraft.value.value = v === '' ? null : Number(v)
  },
})

// Delete-reading confirm open state derives from a target id (mirrors parts/attachment flows).
const deleteReadingOpen = computed({
  get: () => deleteReadingTarget.value !== null,
  set: (open: boolean) => {
    if (!open) deleteReadingTarget.value = null
  },
})

// ── WO Form ───────────────────────────────────────────────────────────────────
// Defensive: the API ref only guarantees sort_order ordering for the template
// endpoint, not explicitly for the WO instance — sort client-side too.
const sortedFormFields = computed(() =>
  (record.value?.form?.fields ?? []).slice().sort((a, b) => a.sort_order - b.sort_order),
)

/** Required fields still unfilled — surfaced on the collapsed summary card. */
const missingRequiredLabels = computed(() =>
  requiredFieldStatus.value.items.filter((i) => !i.done).map((i) => i.label),
)

/**
 * The point of collapsing the checklist is to keep this card short, so name
 * only the first few outstanding fields — a full 20-item list would recreate
 * the noise the sheet exists to remove. The rest are visible in the sheet.
 */
const MISSING_PREVIEW_LIMIT = 3

const missingPreview = computed(() => missingRequiredLabels.value.slice(0, MISSING_PREVIEW_LIMIT))

const missingOverflowCount = computed(() =>
  Math.max(0, missingRequiredLabels.value.length - MISSING_PREVIEW_LIMIT),
)

const checklistOpen = ref(false)

function openChecklist() {
  checklistOpen.value = true
}

async function onChecklistSaved() {
  await load(id.value, { silent: true })
}

// A failed Complete returns the unfilled required fields (422). Open the
// checklist straight away so the user lands on the rows they need to fix
// rather than having to find their way back into the sheet.
watch(missingFields, (missing) => {
  if (missing.size > 0) checklistOpen.value = true
})

watch(
  id,
  async (newId) => {
    if (!newId) return
    await load(newId)
    await loadAssetReadings()
  },
  { immediate: true },
)
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <Button variant="ghost" size="sm" class="detail-back" @click="goBack">
        <ArrowLeftIcon class="detail-back-icon" />
        Back
      </Button>

      <!-- Load states -->
      <div v-if="loading" class="loading-state">Loading work order…</div>
      <DetailNotFound
        v-else-if="notFound"
        entity-label="Work order"
        :identifier="String(route.params.workOrderId)"
        back-label="Browse all work orders"
        :back-to="{ path: '/work-orders', query: { tab: 'all' } }"
      />
      <div v-else-if="forbidden" class="permission-state">
        You don't have permission to view this work order.
      </div>
      <div v-else-if="error" class="error-state" role="alert">{{ error }}</div>

      <template v-else-if="record">
        <!-- Sticky workflow command bar (two rows: identity+actions / progress+assignee) -->
        <div class="detail-command-bar">
          <div class="detail-command-top">
            <div class="detail-command-identity">
              <div class="detail-command-heading">
                <h1 class="detail-command-number">{{ record.number }}</h1>
                <span :class="woStatusClass(record.status)">{{
                  woStatusLabel(record.status)
                }}</span>
                <span :class="priorityClass(record.priority)">{{
                  priorityLabel(record.priority)
                }}</span>
                <span
                  v-if="isCorrectiveOrigin"
                  :class="failureClass(record.maintenance_request?.is_failure)"
                  :title="`Failure classification: ${failureLabel(record.maintenance_request?.is_failure)}`"
                  >{{ failureLabel(record.maintenance_request?.is_failure) }}</span
                >
              </div>
              <p class="detail-command-subtitle">
                {{ originTypeLabel ?? 'Corrective' }} work order · {{ record.asset.name }}
              </p>
            </div>

            <div
              v-if="!isTerminal && (canStart || canComplete || canClose || canCancel)"
              class="detail-command-actions"
            >
              <Button v-if="canCancel" variant="outline" @click="openCancel">Cancel</Button>
              <Button v-if="canStart" :disabled="startLoading" @click="openStart">
                {{ startLoading ? 'Starting…' : startLocationRequired ? 'Start…' : 'Start' }}
              </Button>
              <Button v-if="canComplete" @click="openComplete">Complete…</Button>
              <Button v-if="canClose" @click="openClose">Close…</Button>
            </div>
          </div>

          <div class="detail-command-bottom">
            <ol v-if="!isCancelled" class="wo-stepper" aria-label="Work order lifecycle">
              <li
                v-for="step in lifecycleSteps"
                :key="step.key"
                class="wo-step"
                :data-state="step.state"
              >
                <span class="wo-step-dot" aria-hidden="true"></span>
                <span class="wo-step-label">{{ step.label }}</span>
              </li>
            </ol>
            <div v-else class="wo-stepper-cancelled">Cancelled</div>

            <div class="wo-command-assignee">
              <span class="wo-command-assignee-label">Assignee</span>
              <span class="wo-command-assignee-name">{{
                record.assigned_to?.name ?? (canAssign ? 'Unassigned' : '—')
              }}</span>
              <Button
                v-if="canAssign"
                size="icon-sm"
                variant="outline"
                :title="record.assigned_to ? 'Reassign work order' : 'Assign work order'"
                :aria-label="record.assigned_to ? 'Reassign work order' : 'Assign work order'"
                @click="openAssign"
              >
                <UserPenIcon v-if="record.assigned_to" />
                <UserPlusIcon v-else />
              </Button>
            </div>
          </div>
        </div>

        <!-- Read-only banner for terminal statuses -->
        <div v-if="isTerminal" class="detail-banner">
          This work order is {{ woStatusLabel(record.status).toLowerCase() }} and can no longer be
          changed.
        </div>

        <!-- Execution surface (main) + reference (context) -->
        <div class="detail-layout">
          <div class="detail-main">
            <!-- Work notes -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Work notes</h2>
                <div class="detail-card-actions">
                  <Button v-if="canEdit && !editing" size="sm" variant="outline" @click="startEdit"
                    >Edit</Button
                  >
                  <Button
                    v-if="editing"
                    size="sm"
                    variant="outline"
                    :disabled="saving"
                    @click="cancelEdit"
                    >Cancel</Button
                  >
                  <Button v-if="editing" size="sm" :disabled="saving" @click="saveEdit">
                    {{ saving ? 'Saving…' : 'Save Changes' }}
                  </Button>
                </div>
              </div>
              <div class="detail-card-content">
                <div v-if="editError" class="error-state" role="alert">{{ editError }}</div>
                <div class="detail-grid">
                  <div class="detail-field detail-field-block">
                    <Label v-if="editing" for="wo-description" class="detail-field-label"
                      >Description</Label
                    >
                    <span v-else class="detail-field-label">Description</span>
                    <p v-if="!editing" class="detail-field-value detail-field-prose">
                      {{ record.description ?? 'No description provided.' }}
                    </p>
                    <Textarea
                      v-else
                      id="wo-description"
                      v-model="draft.description"
                      :rows="5"
                      placeholder="Describe the work to be performed…"
                    />
                    <p v-if="editing && validationErrors?.description" class="form-error">
                      {{ validationErrors.description[0] }}
                    </p>
                  </div>
                  <div v-if="record.completion_notes" class="detail-field detail-field-block">
                    <span class="detail-field-label">Completion notes</span>
                    <p class="detail-field-value detail-field-prose">
                      {{ record.completion_notes }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Completion checklist (WO form) — summary only; filled in a sheet -->
            <div v-if="record.form" class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Completion checklist</h2>
                <span
                  v-if="requiredFieldStatus.total > 0"
                  class="wo-checklist-count"
                  :data-complete="requiredFieldStatus.complete"
                  >{{ requiredFieldStatus.done }} / {{ requiredFieldStatus.total }} required</span
                >
              </div>
              <div class="detail-card-content">
                <div
                  v-if="record.form.template_is_stale && !syncDeferred && canEditWoForm"
                  class="wo-form-banner"
                  role="alert"
                >
                  <span>This form was snapshotted from an older template version.</span>
                  <div class="wo-form-banner-actions">
                    <Button size="sm" variant="outline" @click="deferFormSync">Dismiss</Button>
                    <Button size="sm" @click="syncForm">Sync to latest</Button>
                  </div>
                </div>

                <div class="wo-checklist-summary">
                  <div v-if="requiredFieldStatus.total > 0" class="wo-checklist-progress">
                    <Progress
                      :value="(requiredFieldStatus.done / requiredFieldStatus.total) * 100"
                      :variant="requiredFieldStatus.complete ? 'default' : 'due'"
                    />
                  </div>

                  <p class="wo-checklist-meta">
                    {{ sortedFormFields.length }}
                    {{ sortedFormFields.length === 1 ? 'field' : 'fields' }}
                    <template v-if="requiredFieldStatus.total > 0">
                      · {{ requiredFieldStatus.total }} required
                    </template>
                  </p>

                  <div v-if="missingRequiredLabels.length > 0" class="wo-checklist-missing">
                    <span class="wo-checklist-missing-label">Still required:</span>
                    <span class="wo-checklist-missing-items">
                      {{ missingPreview.join(', ')
                      }}<template v-if="missingOverflowCount > 0">
                        +{{ missingOverflowCount }} more</template
                      >
                    </span>
                  </div>

                  <div class="wo-checklist-actions">
                    <Button :variant="canEditWoForm ? 'default' : 'outline'" @click="openChecklist">
                      {{ canEditWoForm ? 'Fill checklist' : 'View checklist' }}
                    </Button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Part Request — the printable warehouse request. Lines are still
                 recorded as parts consumed, so WO history and the consumption
                 report are unaffected by the rename. -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Part Request</h2>
                <div class="detail-card-actions">
                  <Button
                    v-if="parts.length > 0"
                    size="sm"
                    variant="outline"
                    @click="router.push(`/work-orders/${record.id}/part-request`)"
                    >Print…</Button
                  >
                  <Button v-if="canEdit" size="sm" variant="outline" @click="openAddPart"
                    >Add Part…</Button
                  >
                </div>
              </div>
              <div class="data-card-content">
                <div v-if="parts.length === 0" class="empty-state">No parts recorded.</div>
                <table v-else class="detail-table">
                  <thead class="detail-table-head">
                    <tr>
                      <th>Part</th>
                      <th>Quantity</th>
                      <th>Notes</th>
                      <th v-if="canEdit"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="p in parts" :key="p.id" class="detail-table-row">
                      <td class="detail-table-cell">
                        <PartIdentity :part="p.part" stacked />
                      </td>
                      <td class="detail-table-cell">
                        {{ p.quantity
                        }}<span v-if="p.part.unit_of_measure" class="table-cell-secondary">
                          {{ p.part.unit_of_measure }}</span
                        >
                      </td>
                      <td class="detail-table-cell">
                        <span v-if="p.notes">{{ p.notes }}</span>
                        <span v-else class="detail-table-remove">—</span>
                      </td>
                      <td v-if="canEdit" class="detail-table-cell">
                        <div class="detail-table-actions">
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            class="attachment-delete"
                            :title="`Remove ${p.part.name}`"
                            :aria-label="`Remove ${p.part.name}`"
                            @click="openRemovePart(p.id)"
                          >
                            <Trash2Icon />
                          </Button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Updated readings -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Updated readings</h2>
                <div class="detail-card-actions">
                  <Button v-if="canEdit" size="sm" variant="outline" @click="openRecordReading"
                    >Record reading…</Button
                  >
                </div>
              </div>
              <div class="data-card-content">
                <div v-if="readingsLoading" class="loading-state">Loading readings…</div>
                <template v-else>
                  <!-- Readings taken on this work order, kept apart from the
                       asset's earlier history so the table cannot be read as
                       though all of it belongs to this job. -->
                  <div class="reading-group">
                    <p class="reading-group-title">Recorded on this work order</p>
                    <div v-if="workOrderReadings.length === 0" class="empty-state">
                      No readings recorded on this work order yet.
                    </div>
                    <WoReadingsTable
                      v-else
                      :readings="workOrderReadings"
                      :types="readingTypes"
                      :can-manage="canManageReadings"
                      @edit="openEditReading"
                      @remove="openDeleteReading"
                    />
                  </div>

                  <div v-if="historyReadings.length > 0" class="reading-group">
                    <Button
                      variant="ghost"
                      size="sm"
                      class="reading-history-toggle"
                      :aria-expanded="readingHistoryOpen"
                      @click="readingHistoryOpen = !readingHistoryOpen"
                    >
                      <ChevronRightIcon :class="readingHistoryOpen ? 'is-open' : undefined" />
                      Asset reading history ({{ historyReadings.length }} earlier)
                    </Button>
                    <WoReadingsTable
                      v-if="readingHistoryOpen"
                      :readings="historyReadings"
                      :types="readingTypes"
                      :can-manage="canManageReadings"
                      @edit="openEditReading"
                      @remove="openDeleteReading"
                    />
                  </div>
                </template>
                <p v-if="sinceLastService" class="table-cell-secondary detail-field-muted">
                  {{ sinceLastService.type }}: {{ sinceLastService.since }} /
                  {{ sinceLastService.interval }} {{ sinceLastService.unit }} since last service
                </p>
              </div>
            </div>
          </div>

          <aside class="detail-rail">
            <!-- Details -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Details</h2>
              </div>
              <div class="detail-card-content">
                <div class="detail-grid detail-rail-grid">
                  <div class="detail-field detail-field-block">
                    <span class="detail-field-label">Asset</span>
                    <p class="detail-field-value">
                      <AssetIdentity :asset="record.asset" show-tag />
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Priority</span>
                    <p class="detail-field-value">
                      <span :class="priorityClass(record.priority)">{{
                        priorityLabel(record.priority)
                      }}</span>
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Created</span>
                    <p class="detail-field-value">{{ fmtDate(record.created_at) }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Assigned to</span>
                    <p class="detail-field-value">
                      {{ record.assigned_to?.name ?? (canAssign ? 'Unassigned' : '—') }}
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Assigned by</span>
                    <p class="detail-field-value">{{ record.assigned_by?.name ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Started</span>
                    <p class="detail-field-value">{{ fmtDate(record.started_at) }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Completed</span>
                    <p class="detail-field-value">{{ fmtDate(record.completed_at) }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Closed</span>
                    <p class="detail-field-value">{{ fmtDate(record.closed_at) }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Asset status -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Asset status</h2>
                <div class="detail-card-actions">
                  <Button
                    v-if="canSetAssetStatus"
                    size="sm"
                    variant="outline"
                    @click="openSetAssetStatus()"
                    >Update status…</Button
                  >
                </div>
              </div>
              <div class="detail-card-content">
                <div class="detail-grid detail-rail-grid">
                  <div class="detail-field">
                    <span class="detail-field-label">Current status</span>
                    <p class="detail-field-value">
                      <span :class="operationalStatusClass(record.asset.operational_status)">{{
                        operationalStatusLabel(record.asset.operational_status)
                      }}</span>
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Current location</span>
                    <p v-if="record.asset.current_location" class="detail-field-value">
                      <span class="asset-location-value">
                        {{ record.asset.current_location.name }}
                        <span :class="locationTypeClass(record.asset.current_location.type)">{{
                          locationTypeLabel(record.asset.current_location.type)
                        }}</span>
                      </span>
                    </p>
                    <p v-else class="detail-field-muted">No location recorded</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Attachments -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Attachments</h2>
                <div class="detail-card-actions">
                  <Button v-if="canEdit" size="sm" variant="outline" @click="openUpload"
                    >Upload…</Button
                  >
                </div>
              </div>
              <div class="data-card-content">
                <div v-if="attachmentsLoading" class="loading-state">Loading attachments…</div>
                <div v-else-if="attachments.length === 0" class="empty-state">No attachments.</div>
                <table v-else class="detail-table">
                  <thead class="detail-table-head">
                    <tr>
                      <th>File</th>
                      <th>Size</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="a in attachments" :key="a.id" class="detail-table-row">
                      <td class="detail-table-cell">{{ a.file_name }}</td>
                      <td class="detail-table-cell">{{ formatBytes(a.size_bytes) }}</td>
                      <td class="detail-table-cell">
                        <div class="detail-table-actions">
                          <Button
                            v-if="a.download_url"
                            variant="ghost"
                            size="icon-sm"
                            :title="`View ${a.file_name}`"
                            :aria-label="`View ${a.file_name}`"
                            @click="openAttachmentInNewTab(a.download_url, a.file_name)"
                          >
                            <EyeIcon />
                          </Button>
                          <Button
                            v-if="a.can_delete"
                            variant="ghost"
                            size="icon-sm"
                            class="attachment-delete"
                            :title="`Delete ${a.file_name}`"
                            :aria-label="`Delete ${a.file_name}`"
                            @click="openDeleteAttachment(a.id)"
                          >
                            <Trash2Icon />
                          </Button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Related maintenance request -->
            <div v-if="record.maintenance_request" class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Related maintenance request</h2>
              </div>
              <div class="detail-card-content">
                <div class="detail-grid detail-rail-grid">
                  <div class="detail-field">
                    <span class="detail-field-label">Request</span>
                    <p class="detail-field-value">
                      <RouterLink
                        :to="`/maintenance/requests/${record.maintenance_request.id}`"
                        class="table-link"
                      >
                        {{ record.maintenance_request.number }}
                      </RouterLink>
                    </p>
                  </div>
                  <div v-if="originTypeLabel" class="detail-field">
                    <span class="detail-field-label">Type</span>
                    <p class="detail-field-value">{{ originTypeLabel }}</p>
                  </div>
                  <div v-if="isCorrectiveOrigin" class="detail-field">
                    <span class="detail-field-label">Failure</span>
                    <p class="detail-field-value">
                      <span :class="failureClass(record.maintenance_request.is_failure)">{{
                        failureLabel(record.maintenance_request.is_failure)
                      }}</span>
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </aside>
        </div>
      </template>
    </div>

    <!-- Assign technician -->
    <Dialog v-model:open="assignOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Assign work order</DialogTitle>
          <DialogDescription
            >Select an active Technician or Maintenance Manager to assign this work order
            to.</DialogDescription
          >
        </DialogHeader>
        <div class="form-field">
          <Label for="wo-tech">Assignee</Label>
          <div v-if="techniciansLoading" class="loading-state">Loading assignees…</div>
          <Select v-else v-model="selectedTechIdStr">
            <SelectTrigger id="wo-tech"
              ><SelectValue placeholder="Select an assignee"
            /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="t in technicians" :key="t.id" :value="String(t.id)">
                {{ t.name }} <span class="select-item-meta">{{ roleLabel(t.role) }}</span>
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="assignLoading" @click="assignOpen = false"
            >Back</Button
          >
          <Button :disabled="assignLoading || selectedTechId === null" @click="doAssign">
            {{ assignLoading ? 'Assigning…' : 'Assign' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Complete -->
    <Dialog v-model:open="completeOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Complete work order {{ record?.number }}?</DialogTitle>
          <DialogDescription
            >Mark this work order as completed. This cannot be undone.</DialogDescription
          >
        </DialogHeader>
        <div class="form-field">
          <Label for="wo-completion"
            >Completion notes <span class="field-optional">— optional</span></Label
          >
          <Textarea
            id="wo-completion"
            v-model="completionNotes"
            :rows="4"
            placeholder="Summarise the work completed…"
          />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="completeLoading" @click="completeOpen = false"
            >Back</Button
          >
          <Button :disabled="completeLoading" @click="doComplete">
            {{ completeLoading ? 'Completing…' : 'Complete Work Order' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Close -->
    <Dialog v-model:open="closeOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Close work order {{ record?.number }}?</DialogTitle>
          <DialogDescription
            >Closing finalises this work order. Closed work orders are permanent and cannot be
            changed.</DialogDescription
          >
        </DialogHeader>
        <div v-if="isCorrectiveOrigin" class="form-field">
          <Label for="wo-close-failure"
            >Is this a failure? <span class="field-required">*</span></Label
          >
          <Select v-model="closeIsFailureStr">
            <SelectTrigger id="wo-close-failure"
              ><SelectValue placeholder="Confirm the failure classification"
            /></SelectTrigger>
            <SelectContent>
              <SelectItem value="failure">Yes — a genuine failure</SelectItem>
              <SelectItem value="no_failure">No — not a failure</SelectItem>
            </SelectContent>
          </Select>
          <p class="form-help">
            Pre-filled from the review decision — update it if inspection changed the outcome. Used
            in the MTBF metric.
          </p>
        </div>
        <div class="form-field">
          <Label for="wo-close-status">Asset status after close</Label>
          <Select v-model="closeAssetStatus">
            <SelectTrigger id="wo-close-status"
              ><SelectValue placeholder="Is the asset operational again?"
            /></SelectTrigger>
            <SelectContent>
              <SelectItem value="ready_for_field">Ready for Field — back in service</SelectItem>
              <SelectItem value="down">Down — still faulty</SelectItem>
            </SelectContent>
          </Select>
          <p class="form-help">
            Pre-set to Ready for Field — change it to Down only if the repair did not restore the
            asset.
          </p>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="closeLoading" @click="closeOpen = false"
            >Back</Button
          >
          <Button
            :disabled="closeLoading || (isCorrectiveOrigin && closeIsFailure === null)"
            @click="doClose"
          >
            {{ closeLoading ? 'Closing…' : 'Close Work Order' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Cancel -->
    <Dialog v-model:open="cancelOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Cancel work order {{ record?.number }}?</DialogTitle>
          <DialogDescription>A reason is required. This cannot be undone.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="wo-cancel-reason">Reason</Label>
          <Textarea
            id="wo-cancel-reason"
            v-model="cancelReason"
            :rows="4"
            placeholder="Explain why this work order is cancelled…"
          />
        </div>
        <div class="form-field">
          <Label for="wo-cancel-status"
            >Asset status after cancel <span class="field-required">*</span></Label
          >
          <Select v-model="cancelAssetStatusStr">
            <SelectTrigger id="wo-cancel-status"
              ><SelectValue placeholder="Is the asset operational again?"
            /></SelectTrigger>
            <SelectContent>
              <SelectItem value="ready_for_field">Ready for Field — false alarm, asset is fine</SelectItem>
              <SelectItem value="down">Down — still faulty</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="cancelLoading" @click="cancelOpen = false"
            >Back</Button
          >
          <Button
            :disabled="cancelLoading || !cancelReason.trim() || cancelAssetStatus === null"
            @click="doCancel"
          >
            {{ cancelLoading ? 'Cancelling…' : 'Cancel Work Order' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Add part -->
    <Dialog v-model:open="addPartOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add part to request</DialogTitle>
          <DialogDescription
            >Only parts compatible with this asset are listed, most specific
            first.</DialogDescription
          >
        </DialogHeader>
        <div class="form-field">
          <Label for="part">Part</Label>
          <PartCombobox
            v-model="selectedPart"
            input-id="part"
            :compatible-with-asset-id="record?.asset?.id ?? null"
          />
        </div>
        <div class="form-field">
          <Label for="wo-part-qty">Quantity <span class="field-required">*</span></Label>
          <Input id="wo-part-qty" v-model="partQuantityStr" type="number" :min="1" />
        </div>
        <div class="form-field">
          <Label for="wo-part-notes">Notes <span class="field-optional">— optional</span></Label>
          <Textarea
            id="wo-part-notes"
            v-model="partDraft.notes"
            :rows="3"
            placeholder="Optional notes…"
          />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="addPartLoading" @click="addPartOpen = false"
            >Back</Button
          >
          <Button :disabled="addPartLoading || !selectedPart" @click="doAddPart">
            {{ addPartLoading ? 'Adding…' : 'Add Part' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Remove part -->
    <Dialog v-model:open="removeOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Remove part?</DialogTitle>
          <DialogDescription
            >This will remove the selected part from this work order. This cannot be
            undone.</DialogDescription
          >
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" :disabled="removeLoading" @click="removeOpen = false"
            >Back</Button
          >
          <Button variant="destructive" :disabled="removeLoading" @click="doRemovePart">
            {{ removeLoading ? 'Removing…' : 'Remove Part' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Record reading -->
    <Dialog v-model:open="recordReadingOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Record meter reading</DialogTitle>
          <DialogDescription
            >Record a new meter reading for {{ record?.asset.name }}.</DialogDescription
          >
        </DialogHeader>
        <div class="form-field">
          <Label for="wo-reading-type">Reading type <span class="field-required">*</span></Label>
          <Select v-model="readingTypeIdStr">
            <SelectTrigger id="wo-reading-type"
              ><SelectValue placeholder="Select a reading type"
            /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="t in readingTypes" :key="t.id" :value="String(t.id)"
                >{{ t.name }} ({{ t.unit }})</SelectItem
              >
            </SelectContent>
          </Select>
        </div>
        <div class="form-field">
          <Label for="wo-reading-value">Value <span class="field-required">*</span></Label>
          <Input id="wo-reading-value" v-model="readingValueStr" type="number" />
          <p v-if="lastReadingForDraft" class="reading-last-hint">
            Last recorded:
            <b>{{ lastReadingForDraft.value.toLocaleString() }} {{ lastReadingForDraft.unit }}</b>
            · {{ fmtDate(lastReadingForDraft.readAt)
            }}<span v-if="lastReadingForDraft.confirmed"> · confirmed</span>
          </p>
        </div>
        <div v-if="readingBelowLast" class="form-field">
          <div class="reading-warning" role="alert">
            <TriangleAlert class="reading-warning-icon" aria-hidden="true" />
            <span>
              This is lower than the last recorded reading of
              <b
                >{{ lastReadingForDraft?.value.toLocaleString() }}
                {{ lastReadingForDraft?.unit }}</b
              >. Meter readings normally only increase.
            </span>
          </div>
          <div class="reading-ack">
            <Checkbox id="wo-reading-ack" v-model="lowerReadingAcknowledged" />
            <Label for="wo-reading-ack" class="reading-ack-label"
              >This lower reading is correct (e.g. the meter was reset or replaced).</Label
            >
          </div>
        </div>
        <div class="form-field">
          <Label for="wo-reading-at">Read at</Label>
          <DatePicker
            id="wo-reading-at"
            v-model="readingDraft.readAt"
            placeholder="Select a date"
          />
        </div>
        <div class="form-field">
          <Label for="wo-reading-notes">Notes <span class="field-optional">— optional</span></Label>
          <Textarea
            id="wo-reading-notes"
            v-model="readingDraft.notes"
            :rows="3"
            placeholder="Optional notes…"
          />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="readingLoading" @click="recordReadingOpen = false"
            >Back</Button
          >
          <Button
            :disabled="
              readingLoading ||
              readingTypeIdStr === undefined ||
              readingDraft.value == null ||
              (readingBelowLast && !lowerReadingAcknowledged)
            "
            @click="doRecordReading"
          >
            {{ readingLoading ? 'Recording…' : 'Record Reading' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Start — asks for a work location when the asset is not at a workshop or yard -->
    <Dialog v-model:open="startOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Start work order {{ record?.number }}?</DialogTitle>
          <DialogDescription>
            <template v-if="record?.asset.current_location">
              This asset is recorded at {{ record.asset.current_location.name }}. Work orders are
              performed at a workshop or yard — starting will record the move.
            </template>
            <template v-else>
              This asset has no recorded location. Select where the work is being performed —
              starting will record it.
            </template>
          </DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="wo-start-location">
            Move asset to <span class="field-required">*</span>
          </Label>
          <Select v-model="startLocationIdStr">
            <SelectTrigger id="wo-start-location">
              <SelectValue
                :placeholder="locationsLoading ? 'Loading locations…' : 'Select a location…'"
              />
            </SelectTrigger>
            <SelectContent>
              <template v-for="[type, locs] in workLocationGroups" :key="type">
                <SelectGroup>
                  <SelectLabel>{{ locationTypeLabel(type) }}</SelectLabel>
                  <SelectItem v-for="loc in locs" :key="loc.id" :value="String(loc.id)">
                    {{ loc.name }}
                    <span v-if="loc.code" class="select-code-hint">{{ loc.code }}</span>
                  </SelectItem>
                </SelectGroup>
              </template>
            </SelectContent>
          </Select>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="startLoading" @click="startOpen = false"
            >Back</Button
          >
          <Button :disabled="startLoading || startLocationId === null" @click="doStart">
            {{ startLoading ? 'Starting…' : 'Move & Start' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Asset status -->
    <Dialog v-model:open="assetStatusOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Update asset status</DialogTitle>
          <DialogDescription
            >Set the operational status of {{ record?.asset.name }}.</DialogDescription
          >
        </DialogHeader>
        <div class="form-field">
          <Label for="wo-asset-status">Operational status</Label>
          <Select v-model="selectedStatusStr">
            <SelectTrigger id="wo-asset-status"
              ><SelectValue placeholder="Select a status"
            /></SelectTrigger>
            <SelectContent>
              <SelectItem value="ready_for_field">Ready for Field</SelectItem>
              <SelectItem value="under_maintenance">Under Maintenance</SelectItem>
              <SelectItem value="down">Down</SelectItem>
              <SelectItem value="scraped">Scraped</SelectItem>
              <SelectItem value="under_inspection">Under Inspection</SelectItem>
              <SelectItem value="lih">Lost in Hole</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="assetStatusLoading" @click="assetStatusOpen = false"
            >Back</Button
          >
          <Button
            :disabled="assetStatusLoading || selectedStatus === null"
            @click="doSetAssetStatus"
          >
            {{ assetStatusLoading ? 'Updating…' : 'Update Status' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Completion checklist -->
    <WoChecklistSheet
      v-if="record?.form"
      :open="checklistOpen"
      :work-order-id="record.id"
      :fields="sortedFormFields"
      :can-edit="canEditWoForm"
      :missing-fields="missingFields"
      @close="checklistOpen = false"
      @saved="onChecklistSaved"
    />

    <!-- Upload attachments -->
    <Dialog v-model:open="uploadOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Upload attachments</DialogTitle>
          <DialogDescription
            >Attach files to this work order (PDF, images, Office — max 20 MB
            each).</DialogDescription
          >
        </DialogHeader>
        <div class="form-field">
          <Button
            type="button"
            variant="outline"
            class="file-pick-btn"
            @click="fileInputRef?.open()"
          >
            <PaperclipIcon class="detail-back-icon" />
            Choose files
          </Button>
          <FileInput
            ref="fileInputRef"
            multiple
            accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx"
            @change="addFiles"
          />
          <ul v-if="uploadFiles.length > 0" class="file-list">
            <li v-for="(f, i) in uploadFiles" :key="i" class="file-list-item">
              <span class="file-list-name">{{ f.name }}</span>
              <span class="file-list-size">{{ formatBytes(f.size) }}</span>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                class="file-list-remove"
                aria-label="Remove file"
                @click="removeFile(i)"
                >✕</Button
              >
            </li>
          </ul>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="uploadLoading" @click="uploadOpen = false"
            >Back</Button
          >
          <Button :disabled="uploadLoading || uploadFiles.length === 0" @click="doUpload">
            {{ uploadLoading ? 'Uploading…' : 'Upload' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Delete attachment -->
    <Dialog v-model:open="deleteAttachmentOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete attachment?</DialogTitle>
          <DialogDescription
            >This permanently deletes the file. This cannot be undone.</DialogDescription
          >
        </DialogHeader>
        <DialogFooter>
          <Button
            variant="outline"
            :disabled="deleteAttachmentLoading"
            @click="deleteAttachmentOpen = false"
            >Back</Button
          >
          <Button
            variant="destructive"
            :disabled="deleteAttachmentLoading"
            @click="doDeleteAttachment"
          >
            {{ deleteAttachmentLoading ? 'Deleting…' : 'Delete' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Edit meter reading -->
    <Dialog v-model:open="editReadingOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit meter reading</DialogTitle>
          <DialogDescription
            >Update the value, date, or notes for this reading on
            {{ record?.asset.name }}.</DialogDescription
          >
        </DialogHeader>
        <div class="form-field">
          <span class="detail-field-label">Reading type</span>
          <p class="detail-field-value">
            {{
              readingTypes.find((t) => t.id === editReadingDraft.usage_reading_type_id)?.name ??
              'Meter reading'
            }}
          </p>
        </div>
        <div class="form-field">
          <Label for="wo-reading-edit-value">Value <span class="field-required">*</span></Label>
          <Input id="wo-reading-edit-value" v-model="editReadingValueStr" type="number" />
        </div>
        <div class="form-field">
          <Label for="wo-reading-edit-at">Read at</Label>
          <DatePicker
            id="wo-reading-edit-at"
            v-model="editReadingDraft.readAt"
            placeholder="Select a date"
          />
        </div>
        <div class="form-field">
          <Label for="wo-reading-edit-notes"
            >Notes <span class="field-optional">— optional</span></Label
          >
          <Textarea
            id="wo-reading-edit-notes"
            v-model="editReadingDraft.notes"
            :rows="3"
            placeholder="Optional notes…"
          />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="editReadingLoading" @click="editReadingOpen = false"
            >Back</Button
          >
          <Button
            :disabled="editReadingLoading || editReadingDraft.value == null"
            @click="doEditReading"
          >
            {{ editReadingLoading ? 'Saving…' : 'Save Changes' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Delete meter reading -->
    <Dialog v-model:open="deleteReadingOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete meter reading?</DialogTitle>
          <DialogDescription
            >This will remove the reading. This cannot be undone.</DialogDescription
          >
        </DialogHeader>
        <DialogFooter>
          <Button
            variant="outline"
            :disabled="deleteReadingLoading"
            @click="deleteReadingOpen = false"
            >Back</Button
          >
          <Button variant="destructive" :disabled="deleteReadingLoading" @click="doDeleteReading">
            {{ deleteReadingLoading ? 'Deleting…' : 'Delete Reading' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
