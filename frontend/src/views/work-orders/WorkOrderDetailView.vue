<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import {
  ArrowLeftIcon,
  PaperclipIcon,
  PencilIcon,
  UserPlusIcon,
  UserPenIcon,
  EyeIcon,
  Trash2Icon,
} from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
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
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { FileInput } from '@/components/ui/file-input'
import { useWorkOrderDetail } from '@/composables/useWorkOrderDetail'
import { openAttachmentInNewTab } from '@/lib/attachments'
import {
  woStatusClass,
  woStatusLabel,
  priorityClass,
  priorityLabel,
  mrTypeLabel,
  operationalStatusLabel,
  fmtDate,
  formatBytes,
  roleLabel,
} from '@/lib/displayHelpers'
import type { WoFormFieldValue } from '@/types'

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
  doStart,
  completeOpen,
  completeLoading,
  completionNotes,
  openComplete,
  doComplete,
  closeLoading,
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
  assetReadings,
  readingsLoading,
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
  updateFieldValue,
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
const selectedStatusStr = computed({
  get: () => selectedStatus.value ?? undefined,
  set: (v: string | undefined) => {
    selectedStatus.value = v ?? null
  },
})
// Cancel: required asset-status choice (down = still faulty, active = false alarm).
const cancelAssetStatusStr = computed({
  get: () => cancelAssetStatus.value ?? undefined,
  set: (v: string | undefined) => {
    cancelAssetStatus.value = v === 'down' || v === 'active' ? v : null
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

function setTextValue(field: WoFormFieldValue, slot: 'pre' | 'post', value: string | number) {
  if (slot === 'pre') field.pre_value = String(value)
  else field.post_value = String(value)
}

function commitField(field: WoFormFieldValue, slot: 'pre' | 'post' | 'notes') {
  if (slot === 'pre') updateFieldValue(field.id, field.uuid, { pre_value: field.pre_value })
  else if (slot === 'post') updateFieldValue(field.id, field.uuid, { post_value: field.post_value })
  else updateFieldValue(field.id, field.uuid, { notes: field.notes })
}

function setBooleanValue(
  field: WoFormFieldValue,
  slot: 'pre' | 'post',
  checked: boolean | 'indeterminate',
) {
  const value = checked === true ? '1' : '0'
  if (slot === 'pre') field.pre_value = value
  else field.post_value = value
  commitField(field, slot)
}

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
      <div v-else-if="notFound" class="empty-state">Work order not found.</div>
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
              </div>
              <p class="detail-command-subtitle">
                {{ mrTypeLabel(record.maintenance_request?.type ?? 'corrective') }} work order ·
                {{ record.asset.name }}
              </p>
            </div>

            <div
              v-if="!isTerminal && (canStart || canComplete || canClose || canCancel)"
              class="detail-command-actions"
            >
              <Button v-if="canCancel" variant="outline" @click="openCancel">Cancel</Button>
              <Button v-if="canStart" :disabled="startLoading" @click="doStart">
                {{ startLoading ? 'Starting…' : 'Start' }}
              </Button>
              <Button v-if="canComplete" @click="openComplete">Complete…</Button>
              <Button v-if="canClose" :disabled="closeLoading" @click="doClose">
                {{ closeLoading ? 'Closing…' : 'Close' }}
              </Button>
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

            <!-- Completion checklist (WO form) -->
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

                <div class="wo-form-table-scroll">
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
                        v-for="field in sortedFormFields"
                        :key="field.uuid"
                        class="detail-table-row"
                        :class="{ 'wo-form-row-missing': missingFields.has(field.uuid) }"
                      >
                        <td class="detail-table-cell wo-form-field-cell">
                          <span class="wo-form-field-name">{{ field.label }}</span>
                          <span v-if="field.is_required" class="field-required">*</span>
                          <span v-if="field.unit" class="detail-field-muted">
                            ({{ field.unit }})</span
                          >
                        </td>

                        <td class="detail-table-cell" data-label="Pre">
                          <template v-if="field.has_pre_post">
                            <Switch
                              v-if="field.field_type === 'boolean'"
                              :model-value="field.pre_value === '1'"
                              :disabled="!canEditWoForm"
                              :aria-label="`${field.label} — pre`"
                              @update:model-value="(v) => setBooleanValue(field, 'pre', v)"
                            />
                            <Input
                              v-else
                              :model-value="field.pre_value ?? ''"
                              :type="field.field_type === 'numeric' ? 'number' : 'text'"
                              :disabled="!canEditWoForm"
                              :aria-label="`${field.label} — pre`"
                              @update:model-value="(v) => setTextValue(field, 'pre', v)"
                              @blur="commitField(field, 'pre')"
                            />
                          </template>
                          <span v-else class="detail-table-remove">—</span>
                        </td>

                        <td
                          class="detail-table-cell"
                          :data-label="field.has_pre_post ? 'Post / Value' : 'Value'"
                        >
                          <Switch
                            v-if="field.field_type === 'boolean'"
                            :model-value="field.post_value === '1'"
                            :disabled="!canEditWoForm"
                            :aria-label="field.has_pre_post ? `${field.label} — post` : field.label"
                            @update:model-value="(v) => setBooleanValue(field, 'post', v)"
                          />
                          <Input
                            v-else
                            :model-value="field.post_value ?? ''"
                            :type="field.field_type === 'numeric' ? 'number' : 'text'"
                            :disabled="!canEditWoForm"
                            :aria-label="field.has_pre_post ? `${field.label} — post` : field.label"
                            @update:model-value="(v) => setTextValue(field, 'post', v)"
                            @blur="commitField(field, 'post')"
                          />
                        </td>

                        <td class="detail-table-cell" data-label="Notes">
                          <Input
                            :model-value="field.notes ?? ''"
                            :disabled="!canEditWoForm"
                            placeholder="Add note…"
                            :aria-label="`${field.label} — notes`"
                            @update:model-value="
                              (v) => {
                                field.notes = String(v)
                              }
                            "
                            @blur="commitField(field, 'notes')"
                          />
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- Parts used -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Parts used</h2>
                <div class="detail-card-actions">
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
                        <div class="table-cell-stack">
                          <span class="table-cell-primary">{{ p.part.name }}</span>
                          <span class="table-cell-secondary">{{ p.part.erp_part_code }}</span>
                        </div>
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
                <div v-else-if="assetReadings.length === 0" class="empty-state">
                  No meter readings recorded.
                </div>
                <table v-else class="detail-table">
                  <thead class="detail-table-head">
                    <tr>
                      <th>Reading</th>
                      <th>Value</th>
                      <th>Read at</th>
                      <th>Status</th>
                      <th v-if="canManageReadings"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="r in assetReadings" :key="r.id" class="detail-table-row">
                      <td class="detail-table-cell">
                        {{
                          readingTypes.find((t) => t.id === r.usage_reading_type_id)?.name ??
                          'Meter reading'
                        }}
                      </td>
                      <td class="detail-table-cell">{{ r.reading_value }}</td>
                      <td class="detail-table-cell">{{ fmtDate(r.reading_at) }}</td>
                      <td class="detail-table-cell">
                        <span v-if="r.confirmed_at">Confirmed</span>
                        <span v-else class="detail-table-remove">—</span>
                      </td>
                      <td v-if="canManageReadings" class="detail-table-cell">
                        <div v-if="!r.confirmed_at" class="detail-table-actions">
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            :title="`Edit reading ${r.reading_value}`"
                            :aria-label="`Edit reading ${r.reading_value}`"
                            @click="openEditReading(r)"
                          >
                            <PencilIcon />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            class="attachment-delete"
                            :title="`Delete reading ${r.reading_value}`"
                            :aria-label="`Delete reading ${r.reading_value}`"
                            @click="openDeleteReading(r.id)"
                          >
                            <Trash2Icon />
                          </Button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
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
                      {{ record.asset.name }}
                      <span class="detail-field-muted">{{ record.asset.erp_asset_code }}</span>
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
                      {{ operationalStatusLabel(record.asset.operational_status) }}
                    </p>
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
                  <div class="detail-field">
                    <span class="detail-field-label">Type</span>
                    <p class="detail-field-value">
                      {{ mrTypeLabel(record.maintenance_request.type) }}
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
              <SelectItem value="active">Active — false alarm, asset is fine</SelectItem>
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
          <DialogTitle>Add part</DialogTitle>
          <DialogDescription
            >Search the parts catalogue and record the quantity used.</DialogDescription
          >
        </DialogHeader>
        <div class="form-field">
          <Label for="part">Part</Label>
          <PartCombobox v-model="selectedPart" input-id="part" />
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
        </div>
        <div class="form-field">
          <Label for="wo-reading-at">Read at</Label>
          <Input id="wo-reading-at" v-model="readingDraft.readAt" type="date" />
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
              readingLoading || readingTypeIdStr === undefined || readingDraft.value == null
            "
            @click="doRecordReading"
          >
            {{ readingLoading ? 'Recording…' : 'Record Reading' }}
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
              <SelectItem value="active">Active</SelectItem>
              <SelectItem value="under_maintenance">Under Maintenance</SelectItem>
              <SelectItem value="down">Down</SelectItem>
              <SelectItem value="inactive">Inactive</SelectItem>
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
          <Input id="wo-reading-edit-at" v-model="editReadingDraft.readAt" type="date" />
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
