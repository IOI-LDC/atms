<script setup lang="ts">
import { computed, watch, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon, PaperclipIcon, EyeIcon, Trash2Icon } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import PartIdentityBadges from '@/components/app/PartIdentityBadges.vue'
import DetailNotFound from '@/components/app/DetailNotFound.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
import { usePartDetail } from '@/composables/usePartDetail'
import { useListOptions } from '@/composables/useListOptions'
import { openAttachmentInNewTab } from '@/lib/attachments'
import { partStatusClass, partStatusLabel, fmtDate, formatBytes } from '@/lib/displayHelpers'

const route = useRoute()
const router = useRouter()

const id = computed(() => Number(route.params.partId))

const {
  record,
  loading,
  error,
  notFound,
  forbidden,
  load,
  canEdit,
  canUploadAttachment,
  canViewErpMeta,
  canViewErpRaw,
  editOpen,
  confirmEditOpen,
  saving,
  editError,
  validationErrors,
  draft,
  openEdit,
  closeEdit,
  requestSave,
  doSave,
  attachments,
  attachmentsLoading,
  loadAttachments,
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
} = usePartDetail()

const {
  maintenanceCategories,
  maintenanceCategoriesLoading,
  loadMaintenanceCategories,
  assetSizes,
  assetSizesLoading,
  loadAssetSizes,
} = useListOptions()

const maintenanceCategoryValue = computed({
  get: () =>
    draft.value.maintenance_category_id === null
      ? '__none__'
      : String(draft.value.maintenance_category_id),
  set: (value: string) => {
    draft.value.maintenance_category_id = value === '__none__' ? null : Number(value)
  },
})

// FileInput primitive — its open() method is triggered via ref.
const fileInputRef = ref<InstanceType<typeof FileInput> | null>(null)

// Attachment deletion uses its target id as open state (same pattern as Asset/WO detail).
const deleteAttachmentOpen = computed({
  get: () => deleteAttachmentTarget.value !== null,
  set: (open: boolean) => {
    if (!open) deleteAttachmentTarget.value = null
  },
})

const erpRawDataText = computed(() =>
  record.value?.erp_raw_data ? JSON.stringify(record.value.erp_raw_data, null, 2) : null,
)

function goBack() {
  router.back()
}

watch(
  id,
  async (newId) => {
    if (!newId) return
    await load(newId)
    void loadAttachments(newId)
    void loadMaintenanceCategories()
    void loadAssetSizes()
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
      <div v-if="loading" class="loading-state">Loading part…</div>
      <DetailNotFound
        v-else-if="notFound"
        entity-label="Part"
        :identifier="String(route.params.partId)"
        back-label="Browse all parts"
        :back-to="{ path: '/parts' }"
      />
      <div v-else-if="forbidden" class="permission-state">
        You don't have permission to view this part.
      </div>
      <div v-else-if="error" class="error-state" role="alert">{{ error }}</div>

      <template v-else-if="record">
        <!-- ── Command bar ─────────────────────────────────────────────── -->
        <div class="detail-command-bar">
          <div class="detail-command-top">
            <div class="detail-command-identity">
              <div class="detail-command-heading">
                <h1 class="detail-command-number">{{ record.name }}</h1>
                <span :class="partStatusClass(record.is_active)">
                  {{ partStatusLabel(record.is_active) }}
                </span>
              </div>
              <p class="detail-command-subtitle">
                <PartIdentityBadges :part="record" />
              </p>
            </div>
            <div v-if="canEdit" class="detail-command-actions">
              <Button size="sm" @click="openEdit">Edit Part</Button>
            </div>
          </div>
        </div>

        <!-- ── Main (details) + reference rail ───────────────────────────── -->
        <div class="detail-layout">
          <div class="detail-main">
            <!-- ── Overview card ─────────────────────────────────────────── -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Part Details</h2>
              </div>
              <div class="detail-card-content">
                <div class="detail-grid">
                  <div class="detail-field">
                    <span class="detail-field-label">Part Number</span>
                    <p class="detail-field-value">{{ record.part_number ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Size</span>
                    <p class="detail-field-value">{{ record.size ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Maintenance Category</span>
                    <p class="detail-field-value">{{ record.maintenance_category?.name ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Unit of Measure</span>
                    <p class="detail-field-value">{{ record.unit_of_measure ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Available Quantity</span>
                    <p class="detail-field-value">
                      <span
                        v-if="record.available_quantity <= 0"
                        class="status-badge status-inactive"
                        >Out of stock</span
                      >
                      <template v-else>{{ record.available_quantity }}</template>
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Status</span>
                    <p class="detail-field-value">
                      <span :class="partStatusClass(record.is_active)">
                        {{ partStatusLabel(record.is_active) }}
                      </span>
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Created</span>
                    <p class="detail-field-value">{{ fmtDate(record.created_at) }}</p>
                  </div>
                  <div v-if="record.description" class="detail-field detail-field-block">
                    <span class="detail-field-label">Description</span>
                    <p class="detail-field-value detail-field-prose">{{ record.description }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <aside class="detail-rail">
            <!-- ── ERP Reference (Admin/Manager) ─────────────────────────── -->
            <div v-if="canViewErpMeta" class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">ERP Reference</h2>
              </div>
              <div class="detail-card-content">
                <div class="detail-grid detail-rail-grid">
                  <div class="detail-field">
                    <span class="detail-field-label">ERP Status</span>
                    <p class="detail-field-value">{{ record.erp_status ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Last ERP Sync</span>
                    <p class="detail-field-value">{{ fmtDate(record.erp_last_synced_at) }}</p>
                  </div>
                  <div
                    v-if="canViewErpRaw && erpRawDataText"
                    class="detail-field detail-field-block"
                  >
                    <span class="detail-field-label">Raw ERP Data</span>
                    <p class="detail-field-value detail-field-prose">{{ erpRawDataText }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- ── Attachments ──────────────────────────────────────────── -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Attachments</h2>
                <div v-if="canUploadAttachment" class="detail-card-actions">
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

    <!-- ── Edit Part sheet ───────────────────────────────────────────────── -->
    <Sheet
      :open="editOpen"
      :modal="false"
      @update:open="
        (value) => {
          if (!value) closeEdit()
        }
      "
    >
      <SheetContent side="right" class="create-sheet">
        <SheetHeader class="create-sheet-header">
          <SheetTitle>Edit Part</SheetTitle>
          <SheetDescription>
            Update local catalogue values for {{ record?.name }}. A future ERP sync may overwrite
            these values.
          </SheetDescription>
        </SheetHeader>

        <div class="create-sheet-body">
          <div v-if="editError" class="error-state" role="alert">{{ editError }}</div>

          <div class="form-grid">
            <div class="form-field">
              <Label for="edit-part-name">Name <span class="field-required">*</span></Label>
              <Input id="edit-part-name" v-model="draft.name" placeholder="Part name" />
              <p v-if="validationErrors?.name" class="form-error">
                {{ validationErrors.name[0] }}
              </p>
            </div>

            <div class="form-field">
              <Label for="edit-part-quantity">
                Available Qty <span class="field-required">*</span>
              </Label>
              <Input
                id="edit-part-quantity"
                v-model="draft.available_quantity"
                type="number"
                min="0"
                step="0.001"
                inputmode="decimal"
              />
              <p v-if="validationErrors?.available_quantity" class="form-error">
                {{ validationErrors.available_quantity[0] }}
              </p>
            </div>

            <div class="form-field">
              <Label for="edit-part-category">
                Maintenance Category <span class="field-optional">— optional</span>
              </Label>
              <Select v-model="maintenanceCategoryValue">
                <SelectTrigger id="edit-part-category">
                  <SelectValue placeholder="No category" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__none__">No category</SelectItem>
                  <SelectItem
                    v-for="category in maintenanceCategories"
                    :key="category.id"
                    :value="String(category.id)"
                  >
                    {{ category.name }}
                  </SelectItem>
                </SelectContent>
              </Select>
              <p
                v-if="!maintenanceCategoriesLoading && maintenanceCategories.length === 0"
                class="form-help"
              >
                No controlled Maintenance Categories are available.
              </p>
              <p v-if="validationErrors?.maintenance_category_id" class="form-error">
                {{ validationErrors.maintenance_category_id[0] }}
              </p>
            </div>

            <div class="form-field">
              <Label for="edit-part-size">
                Size <span class="field-optional">— optional</span>
              </Label>
              <Select
                :model-value="draft.size_inches || '__none__'"
                @update:model-value="
                  (value) => {
                    const size = String(value)
                    draft.size_inches = size === '__none__' ? '' : size
                  }
                "
              >
                <SelectTrigger id="edit-part-size">
                  <SelectValue placeholder="No size" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="__none__">No size</SelectItem>
                  <SelectItem
                    v-for="size in assetSizes"
                    :key="size.value"
                    :value="String(size.value)"
                  >
                    {{ size.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
              <p v-if="!assetSizesLoading && assetSizes.length === 0" class="form-help">
                No controlled sizes are available.
              </p>
              <p v-if="validationErrors?.size_inches" class="form-error">
                {{ validationErrors.size_inches[0] }}
              </p>
            </div>

            <div class="form-field">
              <Label for="edit-part-status">Status</Label>
              <Select
                :model-value="draft.is_active ? 'true' : 'false'"
                @update:model-value="
                  (value) => {
                    draft.is_active = value === 'true'
                  }
                "
              >
                <SelectTrigger id="edit-part-status"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="true">Active</SelectItem>
                  <SelectItem value="false">Inactive</SelectItem>
                </SelectContent>
              </Select>
              <p v-if="validationErrors?.is_active" class="form-error">
                {{ validationErrors.is_active[0] }}
              </p>
            </div>
          </div>
        </div>

        <div class="create-sheet-footer">
          <Button variant="outline" :disabled="saving" @click="closeEdit">Cancel</Button>
          <Button :disabled="saving" @click="requestSave">Save Changes</Button>
        </div>
      </SheetContent>
    </Sheet>

    <!-- ── Confirm Edit dialog ───────────────────────────────────────────── -->
    <Dialog v-model:open="confirmEditOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Save part changes?</DialogTitle>
          <DialogDescription>
            Update local catalogue values for <strong>{{ record?.name }}</strong
            >. A future ERP sync may overwrite them.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" :disabled="saving" @click="confirmEditOpen = false">
            Back
          </Button>
          <Button :disabled="saving" @click="doSave">
            {{ saving ? 'Saving…' : 'Save Part' }}
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
