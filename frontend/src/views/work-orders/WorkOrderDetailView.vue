<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { ArrowLeftIcon } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { FileInput } from '@/components/ui/file-input'
import { useWorkOrderDetail } from '@/composables/useWorkOrderDetail'
import {
  woStatusClass, woStatusLabel, priorityClass, priorityLabel, mrTypeLabel,
  operationalStatusLabel, fmtDate, formatBytes,
} from '@/lib/displayHelpers'

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.workOrderId))

function goBack() {
  router.back()
}

const {
  record, loading, error, notFound, forbidden,
  attachments, attachmentsLoading,
  isTerminal,
  canEdit, canAssign, canStart, canComplete, canClose, canCancel, canSetAssetStatus,
  editing, saving, editError, draft, validationErrors, startEdit, cancelEdit, saveEdit,
  assignOpen, assignLoading, technicians, techniciansLoading, selectedTechId, openAssign, doAssign,
  startLoading, doStart,
  completeOpen, completeLoading, completionNotes, openComplete, doComplete,
  closeLoading, doClose,
  cancelOpen, cancelLoading, cancelReason, openCancel, doCancel,
  addPartOpen, addPartLoading, partDraft, partsSearch, partsResults, partsSearchLoading, searchParts, openAddPart, doAddPart,
  removeTarget, removeLoading, openRemovePart, doRemovePart,
  readingTypes, recordReadingOpen, readingLoading, readingDraft, assetReadings, readingsLoading, sinceLastService, openRecordReading, doRecordReading, loadAssetReadings,
  assetStatusOpen, assetStatusLoading, selectedStatus, openSetAssetStatus, doSetAssetStatus,
  uploadOpen, uploadLoading, uploadFiles, openUpload, addFiles, removeFile, doUpload,
  load,
} = useWorkOrderDetail()

// FileInput is a view-managed primitive — its `open()` method is exposed via ref.
const fileInputRef = ref<InstanceType<typeof FileInput> | null>(null)

// shadcn-vue Select emits string values; the composable holds numeric IDs.
// These wrappers translate between the two for v-model binding.
const selectedTechIdStr = computed({
  get: () => selectedTechId.value !== null ? String(selectedTechId.value) : undefined,
  set: (v: string | undefined) => { selectedTechId.value = v ? Number(v) : null },
})
const readingTypeIdStr = computed({
  get: () => readingDraft.value.typeId !== null ? String(readingDraft.value.typeId) : undefined,
  set: (v: string | undefined) => { readingDraft.value.typeId = v ? Number(v) : null },
})
const selectedStatusStr = computed({
  get: () => selectedStatus.value ?? undefined,
  set: (v: string | undefined) => { selectedStatus.value = v ?? null },
})

// Numeric/nullable inputs must round-trip through strings for shadcn Input.
const readingValueStr = computed({
  get: () => readingDraft.value.value !== null ? String(readingDraft.value.value) : '',
  set: (v: string) => { readingDraft.value.value = v === '' ? null : Number(v) },
})
const partQuantityStr = computed({
  get: () => String(partDraft.value.quantity),
  set: (v: string) => { partDraft.value.quantity = v === '' ? 0 : Number(v) },
})

// Part removal uses a target id (not a separate open flag) as its open state.
const removeOpen = computed({
  get: () => removeTarget.value !== null,
  set: (open: boolean) => { if (!open) removeTarget.value = null },
})

watch(id, async (newId) => {
  if (!newId) return
  await load(newId)
  await loadAssetReadings()
}, { immediate: true })
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
      <div v-else-if="forbidden" class="permission-state">You don't have permission to view this work order.</div>
      <div v-else-if="error" class="error-state" role="alert">{{ error }}</div>

      <template v-else-if="record">
        <!-- Header -->
        <div class="page-header">
          <div class="page-heading">
            <h1 class="page-title">{{ record.number }}</h1>
            <p class="page-subtitle">Work order · {{ record.asset.name }}</p>
          </div>
          <div class="page-actions">
            <span :class="woStatusClass(record.status)">{{ woStatusLabel(record.status) }}</span>
            <span :class="priorityClass(record.priority)">{{ priorityLabel(record.priority) }}</span>
          </div>
        </div>

        <!-- Read-only banner for terminal statuses -->
        <div v-if="isTerminal" class="detail-banner">
          This work order is {{ woStatusLabel(record.status).toLowerCase() }} and can no longer be changed.
        </div>

        <!-- Details -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Details</h2>
            <div class="detail-card-actions">
              <Button v-if="canEdit && !editing" size="sm" variant="outline" @click="startEdit">Edit</Button>
              <Button v-if="editing" size="sm" variant="outline" :disabled="saving" @click="cancelEdit">Cancel</Button>
              <Button v-if="editing" size="sm" :disabled="saving" @click="saveEdit">
                {{ saving ? 'Saving…' : 'Save Changes' }}
              </Button>
            </div>
          </div>
          <div class="detail-card-content">
            <div v-if="editError" class="error-state" role="alert">{{ editError }}</div>
            <div class="detail-grid">
              <div class="detail-field">
                <span class="detail-field-label">Asset</span>
                <p class="detail-field-value">
                  {{ record.asset.name }}
                  <span class="detail-field-muted">{{ record.asset.erp_asset_code }}</span>
                </p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Priority</span>
                <p class="detail-field-value">
                  <span :class="priorityClass(record.priority)">{{ priorityLabel(record.priority) }}</span>
                </p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Created</span>
                <p class="detail-field-value">{{ fmtDate(record.created_at) }}</p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Assigned to</span>
                <p class="detail-field-value">{{ record.assigned_to?.name ?? '—' }}</p>
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

        <!-- Work notes -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Work notes</h2>
          </div>
          <div class="detail-card-content">
            <div class="detail-grid">
              <div class="detail-field detail-field-block">
                <Label v-if="editing" for="wo-description" class="detail-field-label">Description</Label>
                <span v-else class="detail-field-label">Description</span>
                <p v-if="!editing" class="detail-field-value detail-field-prose">
                  {{ record.description ?? 'No description provided.' }}
                </p>
                <Textarea
                  v-else id="wo-description" v-model="draft.description" :rows="5"
                  placeholder="Describe the work to be performed…"
                />
                <p v-if="editing && validationErrors?.description" class="form-error">
                  {{ validationErrors.description[0] }}
                </p>
              </div>
              <div v-if="record.completion_notes" class="detail-field detail-field-block">
                <span class="detail-field-label">Completion notes</span>
                <p class="detail-field-value detail-field-prose">{{ record.completion_notes }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Related maintenance request -->
        <div v-if="record.maintenance_request" class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Related maintenance request</h2>
          </div>
          <div class="detail-card-content">
            <div class="detail-grid">
              <div class="detail-field">
                <span class="detail-field-label">Request</span>
                <p class="detail-field-value">
                  <RouterLink :to="`/maintenance/requests/${record.maintenance_request.id}`" class="table-link">
                    {{ record.maintenance_request.number }}
                  </RouterLink>
                </p>
              </div>
              <div class="detail-field">
                <span class="detail-field-label">Type</span>
                <p class="detail-field-value">{{ mrTypeLabel(record.maintenance_request.type) }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Parts used -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Parts used</h2>
            <div class="detail-card-actions">
              <Button v-if="canEdit" size="sm" variant="outline" @click="openAddPart">Add Part…</Button>
            </div>
          </div>
          <div class="data-card-content">
            <div v-if="!record.parts || record.parts.length === 0" class="empty-state">No parts recorded.</div>
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
                <tr v-for="p in record.parts" :key="p.id" class="detail-table-row">
                  <td class="detail-table-cell">
                    <div class="table-cell-stack">
                      <span class="table-cell-primary">{{ p.part.name }}</span>
                      <span class="table-cell-secondary">{{ p.part.erp_part_code }}</span>
                    </div>
                  </td>
                  <td class="detail-table-cell">
                    {{ p.quantity }}<span v-if="p.part.unit_of_measure" class="table-cell-secondary"> {{ p.part.unit_of_measure }}</span>
                  </td>
                  <td class="detail-table-cell">
                    <span v-if="p.notes">{{ p.notes }}</span>
                    <span v-else class="detail-table-remove">—</span>
                  </td>
                  <td v-if="canEdit" class="detail-table-cell">
                    <Button variant="ghost" size="sm" @click="openRemovePart(p.id)">Remove</Button>
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
              <Button v-if="canEdit" size="sm" variant="outline" @click="openRecordReading">Record reading…</Button>
            </div>
          </div>
          <div class="data-card-content">
            <div v-if="readingsLoading" class="loading-state">Loading readings…</div>
            <div v-else-if="assetReadings.length === 0" class="empty-state">No meter readings recorded.</div>
            <ul v-else class="attachment-list">
              <li v-for="r in assetReadings" :key="r.id" class="attachment-item">
                <span class="attachment-name">
                  {{ readingTypes.find(t => t.id === r.usage_reading_type_id)?.name ?? 'Meter reading' }}: {{ r.reading_value }}
                </span>
                <span class="attachment-size">{{ fmtDate(r.reading_at) }}</span>
                <span v-if="r.confirmed_at" class="attachment-size">Confirmed</span>
              </li>
            </ul>
            <p v-if="sinceLastService" class="table-cell-secondary detail-field-muted">
              {{ sinceLastService.type }}: {{ sinceLastService.since }} / {{ sinceLastService.interval }} {{ sinceLastService.unit }} since last service
            </p>
          </div>
        </div>

        <!-- Asset status -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Asset status</h2>
            <div class="detail-card-actions">
              <Button v-if="canSetAssetStatus" size="sm" variant="outline" @click="openSetAssetStatus">Update status…</Button>
            </div>
          </div>
          <div class="detail-card-content">
            <div class="detail-grid">
              <div class="detail-field">
                <span class="detail-field-label">Current status</span>
                <p class="detail-field-value">{{ operationalStatusLabel(record.asset.operational_status) }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Attachments -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Attachments</h2>
            <div class="detail-card-actions">
              <Button v-if="canEdit" size="sm" variant="outline" @click="openUpload">Upload…</Button>
            </div>
          </div>
          <div class="data-card-content">
            <div v-if="attachmentsLoading" class="loading-state">Loading attachments…</div>
            <div v-else-if="attachments.length === 0" class="empty-state">No attachments.</div>
            <ul v-else class="attachment-list">
              <li v-for="a in attachments" :key="a.id" class="attachment-item">
                <span class="attachment-name">{{ a.file_name }}</span>
                <span class="attachment-size">{{ formatBytes(a.size_bytes) }}</span>
                <a v-if="a.download_url" class="attachment-download" :href="a.download_url" target="_blank" rel="noopener">Download</a>
              </li>
            </ul>
          </div>
        </div>

        <!-- Workflow actions -->
        <div v-if="!isTerminal && (canAssign || canStart || canComplete || canClose || canCancel)" class="detail-actions">
          <Button v-if="canCancel" variant="outline" @click="openCancel">Cancel</Button>
          <Button v-if="canAssign && !record.assigned_to" @click="openAssign">Assign…</Button>
          <Button v-if="canAssign && record.assigned_to" variant="outline" @click="openAssign">Reassign…</Button>
          <Button v-if="canStart" :disabled="startLoading" @click="doStart">
            {{ startLoading ? 'Starting…' : 'Start' }}
          </Button>
          <Button v-if="canComplete" @click="openComplete">Complete…</Button>
          <Button v-if="canClose" :disabled="closeLoading" @click="doClose">
            {{ closeLoading ? 'Closing…' : 'Close' }}
          </Button>
        </div>
      </template>

    </div>

    <!-- Assign technician -->
    <Dialog v-model:open="assignOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Assign technician</DialogTitle>
          <DialogDescription>Select a technician to assign this work order to.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="wo-tech">Technician</Label>
          <div v-if="techniciansLoading" class="loading-state">Loading technicians…</div>
          <Select v-else v-model="selectedTechIdStr">
            <SelectTrigger id="wo-tech"><SelectValue placeholder="Select a technician" /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="t in technicians" :key="t.id" :value="String(t.id)">{{ t.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="assignLoading" @click="assignOpen = false">Back</Button>
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
          <DialogDescription>Mark this work order as completed. This cannot be undone.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="wo-completion">Completion notes <span class="field-optional">— optional</span></Label>
          <Textarea id="wo-completion" v-model="completionNotes" :rows="4" placeholder="Summarise the work completed…" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="completeLoading" @click="completeOpen = false">Back</Button>
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
          <Textarea id="wo-cancel-reason" v-model="cancelReason" :rows="4" placeholder="Explain why this work order is cancelled…" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="cancelLoading" @click="cancelOpen = false">Back</Button>
          <Button :disabled="cancelLoading || !cancelReason.trim()" @click="doCancel">
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
          <DialogDescription>Search the parts catalogue and record the quantity used.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="wo-part-search">Part</Label>
          <Input
            id="wo-part-search" :model-value="partsSearch" placeholder="Search by name or code…"
            @update:model-value="(v) => searchParts(String(v))"
          />
          <div v-if="partsSearchLoading" class="table-cell-secondary">Searching…</div>
          <ul v-if="partsResults.length > 0" class="file-list">
            <li v-for="r in partsResults" :key="r.id" class="file-list-item">
              <span class="file-list-name">{{ r.name }} <span class="table-cell-secondary">{{ r.erp_part_code }}</span></span>
              <Button type="button" size="sm" :variant="partDraft.partId === r.id ? 'default' : 'outline'" @click="partDraft.partId = r.id">
                {{ partDraft.partId === r.id ? 'Selected' : 'Select' }}
              </Button>
            </li>
          </ul>
        </div>
        <div class="form-field">
          <Label for="wo-part-qty">Quantity <span class="field-required">*</span></Label>
          <Input id="wo-part-qty" v-model="partQuantityStr" type="number" :min="1" />
        </div>
        <div class="form-field">
          <Label for="wo-part-notes">Notes <span class="field-optional">— optional</span></Label>
          <Textarea id="wo-part-notes" v-model="partDraft.notes" :rows="3" placeholder="Optional notes…" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="addPartLoading" @click="addPartOpen = false">Back</Button>
          <Button :disabled="addPartLoading || partDraft.partId === null" @click="doAddPart">
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
          <DialogDescription>This will remove the selected part from this work order. This cannot be undone.</DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" :disabled="removeLoading" @click="removeOpen = false">Back</Button>
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
          <DialogDescription>Record a new meter reading for {{ record?.asset.name }}.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="wo-reading-type">Reading type <span class="field-required">*</span></Label>
          <Select v-model="readingTypeIdStr">
            <SelectTrigger id="wo-reading-type"><SelectValue placeholder="Select a reading type" /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="t in readingTypes" :key="t.id" :value="String(t.id)">{{ t.name }} ({{ t.unit }})</SelectItem>
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
          <Textarea id="wo-reading-notes" v-model="readingDraft.notes" :rows="3" placeholder="Optional notes…" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="readingLoading" @click="recordReadingOpen = false">Back</Button>
          <Button :disabled="readingLoading || readingTypeIdStr === undefined || readingDraft.value == null" @click="doRecordReading">
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
          <DialogDescription>Set the operational status of {{ record?.asset.name }}.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="wo-asset-status">Operational status</Label>
          <Select v-model="selectedStatusStr">
            <SelectTrigger id="wo-asset-status"><SelectValue placeholder="Select a status" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="active">Active</SelectItem>
              <SelectItem value="under_maintenance">Under Maintenance</SelectItem>
              <SelectItem value="down">Down</SelectItem>
              <SelectItem value="inactive">Inactive</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="assetStatusLoading" @click="assetStatusOpen = false">Back</Button>
          <Button :disabled="assetStatusLoading || selectedStatus === null" @click="doSetAssetStatus">
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
          <DialogDescription>Attach files to this work order (PDF, images, Office — max 20 MB each).</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Button type="button" variant="outline" class="file-pick-btn" @click="fileInputRef?.open()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
            Choose files
          </Button>
          <FileInput ref="fileInputRef" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx" @change="addFiles" />
          <ul v-if="uploadFiles.length > 0" class="file-list">
            <li v-for="(f, i) in uploadFiles" :key="i" class="file-list-item">
              <span class="file-list-name">{{ f.name }}</span>
              <span class="file-list-size">{{ formatBytes(f.size) }}</span>
              <Button type="button" variant="ghost" size="icon" class="file-list-remove" aria-label="Remove file" @click="removeFile(i)">✕</Button>
            </li>
          </ul>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="uploadLoading" @click="uploadOpen = false">Back</Button>
          <Button :disabled="uploadLoading || uploadFiles.length === 0" @click="doUpload">
            {{ uploadLoading ? 'Uploading…' : 'Upload' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

  </AppLayout>
</template>
