<script setup lang="ts">
import { computed, watch, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon, PaperclipIcon, EyeIcon, Trash2Icon } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import DetailNotFound from '@/components/app/DetailNotFound.vue'
import { Button } from '@/components/ui/button'
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
  canUploadAttachment,
  canViewErpMeta,
  canViewErpRaw,
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
                <span class="atms-erp-code">{{ record.erp_part_code }}</span>
                <template v-if="record.category"> · {{ record.category }}</template>
              </p>
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
                    <span class="detail-field-label">ERP Code</span>
                    <p class="detail-field-value">
                      <span class="atms-erp-code">{{ record.erp_part_code }}</span>
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Category</span>
                    <p class="detail-field-value">{{ record.category ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Unit of Measure</span>
                    <p class="detail-field-value">{{ record.unit_of_measure ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Available Quantity</span>
                    <p class="detail-field-value">
                      <span v-if="record.available_quantity <= 0" class="status-badge status-inactive">Out of stock</span>
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
