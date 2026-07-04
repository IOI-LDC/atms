<script setup lang="ts">
import { computed, watch, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon, PaperclipIcon, EyeIcon, Trash2Icon } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import DetailNotFound from '@/components/app/DetailNotFound.vue'
import AssetPmSection from '@/components/assets/AssetPmSection.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
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
import { FileInput } from '@/components/ui/file-input'
import { useAssetDetail } from '@/composables/useAssetDetail'
import { useListOptions } from '@/composables/useListOptions'
import { openAttachmentInNewTab } from '@/lib/attachments'
import { toFaSubclassFilterOptions } from '@/lib/assetColumns'
import {
  assetMaintenanceStatusClass,
  assetMaintenanceStatusLabel,
  assetMaintenanceSubStatusLabel,
  assetKindClass,
  assetKindLabel,
  operationalStatusClass,
  operationalStatusLabel,
  priorityClass,
  priorityLabel,
  mrTypeLabel,
  fmtDate,
  formatBytes,
  faSubclassLabel,
} from '@/lib/displayHelpers'
import { useAuthStore } from '@/stores/auth.store'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const id = computed(() => Number(route.params.assetId))

const {
  record,
  loading,
  error,
  notFound,
  forbidden,
  load,
  loadLocationHistory,
  loadMaintenanceHistory,
  loadReadings,
  loadAttachments,
  canEdit,
  canViewSensitive,
  canToggleBooking,
  bookingConfirmOpen,
  bookingLoading,
  requestToggleBooking,
  closeBookingConfirm,
  doToggleBooking,
  editOpen,
  confirmEditOpen,
  saving,
  editError,
  validationErrors,
  draft,
  locations,
  locationsLoading,
  openEdit,
  closeEdit,
  requestSave,
  doSave,
  suggestTagLoading,
  suggestTagCollision,
  suggestTag,
  locationHistory,
  locationHistoryLoading,
  maintenanceHistory,
  maintenanceHistoryLoading,
  readings,
  readingsLoading,
  attachments,
  attachmentsLoading,
  uploadOpen,
  uploadLoading,
  uploadFiles,
  openUpload,
  addUploadFiles,
  removeUploadFile,
  doUpload,
  deleteAttachmentTarget,
  deleteAttachmentLoading,
  openDeleteAttachment,
  doDeleteAttachment,
} = useAssetDetail()

const { faSubclasses, loadFaSubclasses } = useListOptions()
const faSubclassOptions = computed(() => toFaSubclassFilterOptions(faSubclasses.value))

// FileInput primitive — its open() method is triggered via ref.
const fileInputRef = ref<InstanceType<typeof FileInput> | null>(null)

// shadcn-vue Select emits strings; draft holds numeric IDs.
const locationIdStr = computed({
  get: () =>
    draft.value.current_location_id !== null ? String(draft.value.current_location_id) : '__none__',
  set: (v: string) => {
    draft.value.current_location_id = v === '__none__' ? null : Number(v)
  },
})

/**
 * Available maintenance sub-statuses depend on the draft's maintenance_status
 * and asset_kind (per ASSET_STATUS.md):
 * - enrolled + component/package  → installed, ready
 * - enrolled + standalone asset   → (none — sub-status hidden)
 * - withdrawn                     → lih, dbr, disposed, scrapped, other
 */
const availableSubStatuses = computed<{ value: string; label: string }[]>(() => {
  if (draft.value.maintenance_status === 'withdrawn') {
    return [
      { value: 'lih', label: 'Lost in Hole' },
      { value: 'dbr', label: 'Damaged Beyond Repair' },
      { value: 'disposed', label: 'Disposed' },
      { value: 'scrapped', label: 'Scrapped' },
      { value: 'other', label: 'Other' },
    ]
  }
  if (draft.value.asset_kind === 'package' || draft.value.asset_kind === 'component') {
    return [
      { value: 'installed', label: 'Installed' },
      { value: 'ready', label: 'Ready (spare)' },
    ]
  }
  return [] // enrolled standalone asset → no sub-status
})

// Attachment deletion uses its target id as open state (same pattern as WO).
const deleteAttachmentOpen = computed({
  get: () => deleteAttachmentTarget.value !== null,
  set: (open: boolean) => {
    if (!open) deleteAttachmentTarget.value = null
  },
})

function goBack() {
  router.back()
}

watch(
  id,
  async (newId) => {
    if (!newId) return
    await load(newId)
    void loadLocationHistory(newId)
    void loadReadings(newId)
    void loadAttachments(newId)
    void loadFaSubclasses()
    // Logistics receives 403 on maintenance-history — the composable handles it silently.
    if (!auth.isLogistics) void loadMaintenanceHistory(newId)
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

      <!-- ── Load / error states ──────────────────────────────────────── -->
      <div v-if="loading" class="loading-state">Loading asset…</div>
      <DetailNotFound
        v-else-if="notFound"
        entity-label="Asset"
        :identifier="String(route.params.assetId)"
        back-label="Browse all assets"
        :back-to="{ path: '/assets' }"
      />
      <div v-else-if="forbidden" class="permission-state">
        You don't have permission to view this asset.
      </div>
      <div v-else-if="error" class="error-state" role="alert">{{ error }}</div>

      <template v-else-if="record">
        <!-- ── Sticky command bar (identity + status + actions; no lifecycle) ── -->
        <div class="detail-command-bar">
          <div class="detail-command-top">
            <div class="detail-command-identity">
              <div class="detail-command-heading">
                <h1 class="detail-command-number">{{ record.name }}</h1>
                <span :class="assetMaintenanceStatusClass(record.maintenance_status)">
                  {{ assetMaintenanceStatusLabel(record.maintenance_status) }}
                </span>
                <span :class="operationalStatusClass(record.operational_status)">
                  {{ operationalStatusLabel(record.operational_status) }}
                </span>
                <span v-if="record.is_booked" class="status-badge status-booked">Booked</span>
              </div>
              <p class="detail-command-subtitle">
                <span class="atms-erp-code">{{ record.asset_tag ?? record.erp_asset_code }}</span>
                · {{ assetKindLabel(record.asset_kind) }}
              </p>
            </div>

            <div class="detail-command-actions">
              <Button
                v-if="canToggleBooking"
                size="sm"
                variant="outline"
                @click="requestToggleBooking"
                >{{ record.is_booked ? 'Unbook' : 'Book' }}</Button
              >
              <Button v-if="canEdit" size="sm" @click="openEdit">Edit Asset</Button>
            </div>
          </div>
        </div>

        <!-- ── Main (details + histories) + reference rail ──────────────────── -->
        <div class="detail-layout">
          <div class="detail-main">
            <!-- ── Overview card ─────────────────────────────────────────── -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Asset Details</h2>
              </div>
              <div class="detail-card-content">
                <div class="detail-grid">
                  <div class="detail-field">
                    <span class="detail-field-label">Asset Tag</span>
                    <p class="detail-field-value">
                      <span class="atms-erp-code">{{ record.asset_tag ?? '—' }}</span>
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">ERP Asset Code</span>
                    <p class="detail-field-value">
                      <span class="atms-erp-code">{{ record.erp_asset_code }}</span>
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Asset Class</span>
                    <p class="detail-field-value">
                      {{ record.fa_subclass_code ? faSubclassLabel(record.fa_subclass_code) : '—' }}
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Kind</span>
                    <p class="detail-field-value">
                      <span :class="assetKindClass(record.asset_kind)">
                        {{ assetKindLabel(record.asset_kind) }}
                      </span>
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Manufacturer</span>
                    <p class="detail-field-value">{{ record.manufacturer ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Model</span>
                    <p class="detail-field-value">{{ record.model ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Serial Number</span>
                    <p class="detail-field-value">{{ record.serial_number ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Operational Status</span>
                    <p class="detail-field-value">
                      <span :class="operationalStatusClass(record.operational_status)">
                        {{ operationalStatusLabel(record.operational_status) }}
                      </span>
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Maintenance Status</span>
                    <p class="detail-field-value">
                      <span :class="assetMaintenanceStatusClass(record.maintenance_status)">
                        {{ assetMaintenanceStatusLabel(record.maintenance_status) }}
                      </span>
                      <span v-if="record.maintenance_sub_status" class="detail-field-muted">
                        · {{ assetMaintenanceSubStatusLabel(record.maintenance_sub_status) }}
                      </span>
                    </p>
                  </div>
                  <div v-if="auth.isAdminOrManager" class="detail-field">
                    <span class="detail-field-label">Record Active</span>
                    <p class="detail-field-value">{{ record.is_active ? 'Yes' : 'No' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Current Location</span>
                    <p class="detail-field-value">{{ record.current_location?.name ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Created</span>
                    <p class="detail-field-value">{{ fmtDate(record.created_at) }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Last Updated</span>
                    <p class="detail-field-value">{{ fmtDate(record.updated_at) }}</p>
                  </div>
                  <div v-if="record.description" class="detail-field detail-field-block">
                    <span class="detail-field-label">Description</span>
                    <p class="detail-field-value detail-field-prose">{{ record.description }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── Location History card ──────────────────────────────────── -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Location History</h2>
              </div>
              <div class="data-card-content">
                <div v-if="locationHistoryLoading" class="loading-state">
                  Loading location history…
                </div>
                <div v-else-if="locationHistory.length === 0" class="empty-state">
                  No location changes recorded.
                </div>
                <table v-else class="detail-table">
                  <thead class="detail-table-head">
                    <tr>
                      <th>Effective Date</th>
                      <th>From</th>
                      <th>To</th>
                      <th>Reason</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="h in locationHistory" :key="h.id" class="detail-table-row">
                      <td class="detail-table-cell">{{ fmtDate(h.effective_at) }}</td>
                      <td class="detail-table-cell detail-field-muted">
                        {{ h.from_location?.name ?? '—' }}
                      </td>
                      <td class="detail-table-cell">
                        {{ h.to_location?.name ?? '—' }}
                      </td>
                      <td class="detail-table-cell">{{ h.reason ?? '—' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- ── Maintenance History card (not Logistics) ───────────────── -->
            <div v-if="!auth.isLogistics" class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Maintenance History</h2>
              </div>
              <div class="data-card-content">
                <div v-if="maintenanceHistoryLoading" class="loading-state">
                  Loading maintenance history…
                </div>
                <div v-else-if="maintenanceHistory.length === 0" class="empty-state">
                  No closed work orders on record.
                </div>
                <table v-else class="detail-table">
                  <thead class="detail-table-head">
                    <tr>
                      <th>Date</th>
                      <th>Work Order</th>
                      <th>Type</th>
                      <th>Priority</th>
                      <th>Description</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="h in maintenanceHistory"
                      :key="h.work_order_number"
                      class="detail-table-row"
                    >
                      <td class="detail-table-cell">{{ fmtDate(h.closed_at) }}</td>
                      <td class="detail-table-cell">
                        <span class="atms-wo-number">{{ h.work_order_number }}</span>
                      </td>
                      <td class="detail-table-cell">{{ mrTypeLabel(h.type ?? 'corrective') }}</td>
                      <td class="detail-table-cell">
                        <span :class="priorityClass(h.priority)">{{
                          priorityLabel(h.priority)
                        }}</span>
                      </td>
                      <td class="detail-table-cell table-cell-truncate">
                        {{ h.description ?? '—' }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- ── Usage Readings card ────────────────────────────────────── -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Usage Readings</h2>
              </div>
              <div class="data-card-content">
                <div v-if="readingsLoading" class="loading-state">Loading readings…</div>
                <div v-else-if="readings.length === 0" class="empty-state">
                  No meter readings recorded.
                </div>
                <table v-else class="detail-table">
                  <thead class="detail-table-head">
                    <tr>
                      <th>Read At</th>
                      <th>Value</th>
                      <th>Source</th>
                      <th>Confirmed</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="r in readings" :key="r.id" class="detail-table-row">
                      <td class="detail-table-cell">{{ fmtDate(r.reading_at) }}</td>
                      <td class="detail-table-cell table-cell-primary">{{ r.reading_value }}</td>
                      <td class="detail-table-cell">
                        {{ r.source === 'manual' ? 'Manual' : 'User' }}
                      </td>
                      <td class="detail-table-cell">
                        <span v-if="r.confirmed_at" class="status-badge status-active"
                          >Confirmed</span
                        >
                        <span v-else class="status-badge status-inactive">Unverified</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- ── PM Rules (Admin / Manager) ───────────────────────────────────── -->
            <AssetPmSection
              v-if="auth.isAdminOrManager && record"
              :asset-id="record.id"
              :can-manage="auth.isAdminOrManager"
            />
          </div>

          <aside class="detail-rail">
            <!-- ── ERP Reference (not Logistics) ────────────────────────── -->
            <div v-if="canViewSensitive" class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">ERP Reference</h2>
              </div>
              <div class="detail-card-content">
                <div class="detail-grid detail-rail-grid">
                  <div class="detail-field">
                    <span class="detail-field-label">ERP Asset Code</span>
                    <p class="detail-field-value">
                      <span class="atms-erp-code">{{ record.erp_asset_code }}</span>
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">ERP Status</span>
                    <p class="detail-field-value">{{ record.erp_status ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Last ERP Sync</span>
                    <p class="detail-field-value">{{ fmtDate(record.erp_last_synced_at) }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── Attachments ──────────────────────────────────────────── -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Attachments</h2>
                <div class="detail-card-actions">
                  <Button size="sm" variant="outline" @click="openUpload">
                    <PaperclipIcon class="icon-sm" />
                    Upload…
                  </Button>
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
          </aside>
        </div>
      </template>
    </div>

    <!-- ── Edit Asset sheet ───────────────────────────────────────────────────
         modal=false: prevents reka-ui's useHideOthers + focus-trap from blocking
         the portaled Location Select inside the sheet. Overlay/X/Esc still work.
         See ATMS_UI_RULES.md §8.3. -->
    <Sheet
      :open="editOpen"
      :modal="false"
      @update:open="
        (v) => {
          if (!v) closeEdit()
        }
      "
    >
      <SheetContent side="right" class="create-sheet">
        <SheetHeader class="create-sheet-header">
          <SheetTitle>Edit Asset</SheetTitle>
          <SheetDescription> Update operational details for {{ record?.name }}. </SheetDescription>
        </SheetHeader>

        <div class="create-sheet-body">
          <div v-if="editError" class="error-state" role="alert">{{ editError }}</div>

          <!-- Asset Tag — full-width, above the main grid -->
          <div class="form-field">
            <Label for="edit-asset-tag">Asset Tag</Label>

            <!-- Tag already set: read-only; immutable once saved -->
            <div v-if="record?.asset_tag" class="asset-tag-readonly">
              <span class="atms-erp-code">{{ record.asset_tag }}</span>
              <span class="detail-field-muted">— immutable once saved</span>
            </div>

            <!-- Tag is null: allow Admin/Manager to assign one -->
            <template v-else>
              <div class="asset-tag-assign">
                <Input
                  id="edit-asset-tag"
                  v-model="draft.asset_tag"
                  placeholder="e.g. L-MTR-958-0011"
                  :aria-describedby="suggestTagCollision ? 'tag-collision-msg' : undefined"
                />
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  :disabled="suggestTagLoading"
                  @click="suggestTag"
                >
                  {{ suggestTagLoading ? 'Generating…' : 'Suggest Tag' }}
                </Button>
              </div>
              <p v-if="suggestTagCollision" id="tag-collision-msg" class="form-error">
                Auto-generated tag collides with an existing one. Enter a unique tag manually.
              </p>
              <p v-if="validationErrors?.asset_tag" class="form-error">
                {{ validationErrors.asset_tag[0] }}
              </p>
              <p class="form-help">
                Format: <span class="atms-erp-code">L-BBB-CCC-XXXX</span>. Use "Suggest Tag" to
                generate from ERP data, or enter manually.
              </p>
            </template>
          </div>

          <div class="form-grid">
            <!-- Name -->
            <div class="form-field">
              <Label for="edit-name">Name <span class="field-required">*</span></Label>
              <Input id="edit-name" v-model="draft.name" placeholder="Asset name" />
              <p v-if="validationErrors?.name" class="form-error">
                {{ validationErrors.name[0] }}
              </p>
            </div>

            <!-- Asset Class — editable Select for Admin/Manager -->
            <div class="form-field">
              <Label for="edit-fa-subclass">Asset Class</Label>
              <Select
                :model-value="draft.fa_subclass_code || '__none__'"
                @update:model-value="
                  (v) => {
                    const s = String(v)
                    draft.fa_subclass_code = s === '__none__' ? '' : s
                  }
                "
              >
                <SelectTrigger id="edit-fa-subclass">
                  <SelectValue placeholder="Not classified" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__none__">Not classified</SelectItem>
                  <SelectItem
                    v-for="opt in faSubclassOptions"
                    :key="opt.value"
                    :value="opt.value"
                    >{{ opt.label }}</SelectItem
                  >
                </SelectContent>
              </Select>
              <p v-if="validationErrors?.fa_subclass_code" class="form-error">
                {{ validationErrors.fa_subclass_code[0] }}
              </p>
            </div>

            <!-- Manufacturer -->
            <div class="form-field">
              <Label for="edit-manufacturer">
                Manufacturer <span class="field-optional">— optional</span>
              </Label>
              <Input
                id="edit-manufacturer"
                v-model="draft.manufacturer"
                placeholder="e.g. Baker Hughes"
              />
            </div>

            <!-- Model -->
            <div class="form-field">
              <Label for="edit-model"> Model <span class="field-optional">— optional</span> </Label>
              <Input id="edit-model" v-model="draft.model" placeholder="Model number or name" />
            </div>

            <!-- Serial Number -->
            <div class="form-field">
              <Label for="edit-serial">
                Serial Number <span class="field-optional">— optional</span>
              </Label>
              <Input id="edit-serial" v-model="draft.serial_number" placeholder="Serial number" />
            </div>

            <!-- Asset Kind -->
            <div class="form-field">
              <Label for="edit-kind">Kind</Label>
              <Select v-model="draft.asset_kind">
                <SelectTrigger id="edit-kind"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="asset">Asset — standalone unit</SelectItem>
                  <SelectItem value="package">Package — can contain components</SelectItem>
                  <SelectItem value="component">Component — installable in a package</SelectItem>
                </SelectContent>
              </Select>
              <p v-if="validationErrors?.asset_kind" class="form-error">
                {{ validationErrors.asset_kind[0] }}
              </p>
            </div>

            <!-- Operational Status -->
            <div class="form-field">
              <Label for="edit-op-status">Operational Status</Label>
              <Select v-model="draft.operational_status">
                <SelectTrigger id="edit-op-status"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="under_maintenance">Under Maintenance</SelectItem>
                  <SelectItem value="down">Down</SelectItem>
                  <SelectItem value="inactive">Inactive</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <!-- Maintenance Status (enrolled/withdrawn) -->
            <div class="form-field">
              <Label for="edit-maint-status">Maintenance Status</Label>
              <Select v-model="draft.maintenance_status">
                <SelectTrigger id="edit-maint-status"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="enrolled"
                    >In maintenance program — eligible for maintenance workflows</SelectItem
                  >
                  <SelectItem value="withdrawn">Withdrawn — excluded from all workflows</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <!-- Maintenance Sub-Status (conditional on status + kind) -->
            <div v-if="availableSubStatuses.length > 0" class="form-field">
              <Label for="edit-maint-sub">
                Maintenance Sub-Status
                <span class="field-optional">— optional</span>
              </Label>
              <Select
                :model-value="draft.maintenance_sub_status || '__none__'"
                @update:model-value="
                  (v) => {
                    const s = String(v)
                    draft.maintenance_sub_status = s === '__none__' ? '' : s
                  }
                "
              >
                <SelectTrigger id="edit-maint-sub"
                  ><SelectValue placeholder="None"
                /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="__none__">None</SelectItem>
                  <SelectItem
                    v-for="opt in availableSubStatuses"
                    :key="opt.value"
                    :value="opt.value"
                    >{{ opt.label }}</SelectItem
                  >
                </SelectContent>
              </Select>
              <p v-if="validationErrors?.maintenance_sub_status" class="form-error">
                {{ validationErrors.maintenance_sub_status[0] }}
              </p>
            </div>

            <!-- Record Active (Admin/Manager only) -->
            <div v-if="auth.isAdminOrManager" class="form-field">
              <Label for="edit-is-active">Record Status</Label>
              <Select
                :model-value="draft.is_active ? 'true' : 'false'"
                @update:model-value="
                  (v) => {
                    draft.is_active = v === 'true'
                  }
                "
              >
                <SelectTrigger id="edit-is-active"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="true">Active — included in maintenance workflows</SelectItem>
                  <SelectItem value="false">Inactive — excluded from all workflows</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <!-- Location (full width) -->
          <div class="form-field">
            <Label for="edit-location">
              Location <span class="field-optional">— optional</span>
            </Label>
            <div v-if="locationsLoading" class="detail-field-muted">Loading locations…</div>
            <Select v-else v-model="locationIdStr">
              <SelectTrigger id="edit-location">
                <SelectValue placeholder="No location assigned" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__none__">No location</SelectItem>
                <SelectItem v-for="loc in locations" :key="loc.id" :value="String(loc.id)">{{
                  loc.name
                }}</SelectItem>
              </SelectContent>
            </Select>
            <p v-if="validationErrors?.current_location_id" class="form-error">
              {{ validationErrors.current_location_id[0] }}
            </p>
          </div>

          <!-- Location notes (shown when location might be changed) -->
          <div class="form-field">
            <Label for="edit-location-notes">
              Location Change Notes <span class="field-optional">— optional</span>
            </Label>
            <Input
              id="edit-location-notes"
              v-model="draft.location_notes"
              placeholder="Reason for location change — recorded in location history"
            />
          </div>

          <!-- Description (full width) -->
          <div class="form-field">
            <Label for="edit-description">
              Description <span class="field-optional">— optional</span>
            </Label>
            <Textarea
              id="edit-description"
              v-model="draft.description"
              :rows="4"
              placeholder="Describe the asset, its purpose, or any relevant notes…"
            />
            <p v-if="validationErrors?.description" class="form-error">
              {{ validationErrors.description[0] }}
            </p>
          </div>
        </div>

        <div class="create-sheet-footer">
          <Button variant="outline" :disabled="saving" @click="closeEdit">Cancel</Button>
          <Button :disabled="saving" @click="requestSave">Save Changes</Button>
        </div>
      </SheetContent>
    </Sheet>

    <!-- ── Confirm Edit dialog ─────────────────────────────────────────────── -->
    <Dialog
      :open="bookingConfirmOpen"
      @update:open="
        (v) => {
          if (!v) closeBookingConfirm()
        }
      "
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{{
            record?.is_booked ? 'Unbook this asset?' : 'Book this asset?'
          }}</DialogTitle>
          <DialogDescription>
            <template v-if="record?.is_booked">
              This releases the reservation on <strong>{{ record?.name }}</strong
              >, making it freely available again.
            </template>
            <template v-else>
              This reserves <strong>{{ record?.name }}</strong> for a Job/Project. It stays
              available for maintenance, and the booking auto-releases if the asset is moved or
              deactivated.
            </template>
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" :disabled="bookingLoading" @click="closeBookingConfirm"
            >Back</Button
          >
          <Button :disabled="bookingLoading" @click="doToggleBooking">
            {{ bookingLoading ? 'Saving…' : record?.is_booked ? 'Unbook Asset' : 'Book Asset' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog v-model:open="confirmEditOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Save asset changes?</DialogTitle>
          <DialogDescription>
            Update details for <strong>{{ record?.name }}</strong
            >. This will overwrite the current values.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" :disabled="saving" @click="confirmEditOpen = false">
            Back
          </Button>
          <Button :disabled="saving" @click="doSave">
            {{ saving ? 'Saving…' : 'Save Asset' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- ── Upload attachments dialog ─────────────────────────────────────── -->
    <Dialog v-model:open="uploadOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Upload attachments</DialogTitle>
          <DialogDescription>
            Attach files to {{ record?.name }} (PDF, images, Office — max 20 MB each).
          </DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Button
            type="button"
            variant="outline"
            class="file-pick-btn"
            @click="fileInputRef?.open()"
          >
            <PaperclipIcon class="icon-sm" />
            Choose files
          </Button>
          <FileInput
            ref="fileInputRef"
            multiple
            accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx"
            @change="addUploadFiles"
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
                @click="removeUploadFile(i)"
                >✕</Button
              >
            </li>
          </ul>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="uploadLoading" @click="uploadOpen = false">
            Back
          </Button>
          <Button :disabled="uploadLoading || uploadFiles.length === 0" @click="doUpload(id)">
            {{ uploadLoading ? 'Uploading…' : 'Upload' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- ── Delete attachment dialog ──────────────────────────────────────── -->
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
  </AppLayout>
</template>
