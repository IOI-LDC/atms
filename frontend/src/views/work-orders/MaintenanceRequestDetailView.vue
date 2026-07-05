<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { ArrowLeftIcon, EyeIcon, Trash2Icon } from '@lucide/vue'
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
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useMaintenanceRequestDetail } from '@/composables/useMaintenanceRequestDetail'
import { useListOptions } from '@/composables/useListOptions'
import { openAttachmentInNewTab } from '@/lib/attachments'
import {
  mrStatusClass,
  mrStatusLabel,
  priorityClass,
  priorityLabel,
  priorityPickerLabel,
  mrTypeLabel,
  failureClass,
  failureLabel,
  fmtDate,
  formatBytes,
  roleLabel,
} from '@/lib/displayHelpers'

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.requestId))

// Return to the previous page (the MR list, with its ?tab= preserved). Note:
// in-table sort/filter/page state isn't yet URL-persisted, so those reset —
// see docs/04-frontend/Issues.md (filters + pagination) for the follow-up.
function goBack() {
  router.back()
}

const {
  record,
  loading,
  error,
  notFound,
  forbidden,
  editing,
  saving,
  editError,
  draft,
  validationErrors,
  attachments,
  attachmentsLoading,
  deleteAttachmentTarget,
  deleteAttachmentLoading,
  canDeleteAttachment,
  openDeleteAttachment,
  closeDeleteAttachment,
  doDeleteAttachment,
  isTerminal,
  isCorrective,
  canEdit,
  canApprove,
  canReject,
  canCancel,
  load,
  startEdit,
  cancelEdit,
  saveEdit,
  approveOpen,
  approveLoading,
  approveIsFailure,
  openApprove,
  doApprove,
  approveTechnicians,
  approveTechniciansLoading,
  selectedApproveTechId,
  rejectOpen,
  rejectLoading,
  rejectReason,
  openReject,
  doReject,
  cancelOpen,
  cancelLoading,
  cancelReason,
  openCancel,
  doCancel,
} = useMaintenanceRequestDetail()

const { priorities, loadPriorities } = useListOptions()
loadPriorities()

// shadcn-vue Select emits string values; the composable holds a numeric id or
// null. '__none__' is the explicit "leave unassigned" sentinel.
const selectedApproveTechIdStr = computed({
  get: () =>
    selectedApproveTechId.value !== null ? String(selectedApproveTechId.value) : '__none__',
  set: (v: string | undefined) => {
    selectedApproveTechId.value = !v || v === '__none__' ? null : Number(v)
  },
})

// shadcn-vue Select emits strings; the failure decision is a boolean|null. undefined
// keeps the placeholder showing until the reviewer explicitly picks failure/no_failure.
const approveIsFailureStr = computed<string | undefined>({
  get: () =>
    approveIsFailure.value === null ? undefined : approveIsFailure.value ? 'failure' : 'no_failure',
  set: (v: string | undefined) => {
    approveIsFailure.value = v === 'failure' ? true : v === 'no_failure' ? false : null
  },
})

watch(
  id,
  (newId) => {
    if (newId) load(newId)
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
      <div v-if="loading" class="loading-state">Loading request…</div>
      <DetailNotFound
        v-else-if="notFound"
        entity-label="Maintenance request"
        :identifier="String(route.params.requestId)"
        back-label="Browse all requests"
        :back-to="{ path: '/maintenance', query: { tab: 'all-requests' } }"
      />
      <div v-else-if="forbidden" class="permission-state">
        You don't have permission to view this request.
      </div>
      <div v-else-if="error" class="error-state" role="alert">{{ error }}</div>

      <template v-else-if="record">
        <!-- Command bar -->
        <div class="detail-command-bar">
          <div class="detail-command-top">
            <div class="detail-command-identity">
              <div class="detail-command-heading">
                <h1 class="detail-command-number">{{ record.number }}</h1>
                <span :class="mrStatusClass(record.status)">{{
                  mrStatusLabel(record.status)
                }}</span>
                <span :class="priorityClass(record.priority)">{{
                  priorityLabel(record.priority)
                }}</span>
                <span
                  v-if="isCorrective"
                  :class="failureClass(record.is_failure)"
                  :title="`Failure classification: ${failureLabel(record.is_failure)}`"
                  >{{ failureLabel(record.is_failure) }}</span
                >
              </div>
              <p class="detail-command-subtitle">
                {{ mrTypeLabel(record.type) }} maintenance request · {{ record.asset.name }}
              </p>
            </div>

            <div v-if="canApprove || canReject || canCancel" class="detail-command-actions">
              <Button v-if="canCancel" variant="outline" @click="openCancel">Cancel Request</Button>
              <Button v-if="canReject" variant="outline" @click="openReject">Reject Request</Button>
              <Button v-if="canApprove" @click="openApprove"
                >Approve &amp; Create Work Order</Button
              >
            </div>
          </div>
        </div>

        <!-- Read-only banner for terminal statuses -->
        <div v-if="isTerminal" class="detail-banner">
          This request is {{ mrStatusLabel(record.status).toLowerCase() }} and can no longer be
          changed.
        </div>

        <!-- Details (main) + reference rail -->
        <div class="detail-layout">
          <div class="detail-main">
            <!-- Details card -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Details</h2>
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

                <!-- Field grid: short read-only meta + editable priority -->
                <div class="detail-grid">
                  <div class="detail-field detail-field-block">
                    <span class="detail-field-label">Asset</span>
                    <p class="detail-field-value">
                      {{ record.asset.name }}
                      <span class="detail-field-muted">{{ record.asset.erp_asset_code }}</span>
                    </p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Requested by</span>
                    <p class="detail-field-value">{{ record.created_by?.name ?? '—' }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Created</span>
                    <p class="detail-field-value">{{ fmtDate(record.created_at) }}</p>
                  </div>
                  <div class="detail-field">
                    <span class="detail-field-label">Approved by</span>
                    <p class="detail-field-value">{{ record.reviewed_by?.name ?? '—' }}</p>
                  </div>

                  <!-- Priority (read / edit) -->
                  <div class="detail-field">
                    <Label v-if="editing" for="mr-priority" class="detail-field-label"
                      >Priority</Label
                    >
                    <span v-else class="detail-field-label">Priority</span>
                    <p v-if="!editing" class="detail-field-value">
                      <span :class="priorityClass(record.priority)">{{
                        priorityLabel(record.priority)
                      }}</span>
                    </p>
                    <Select v-else v-model="draft.priority">
                      <SelectTrigger id="mr-priority"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem v-for="opt in priorities" :key="opt.value" :value="opt.value">{{
                          priorityPickerLabel(opt)
                        }}</SelectItem>
                      </SelectContent>
                    </Select>
                    <p v-if="editing && validationErrors?.priority" class="form-error">
                      {{ validationErrors.priority[0] }}
                    </p>
                  </div>

                  <!-- Description (read / edit) — full width -->
                  <div class="detail-field detail-field-block">
                    <Label v-if="editing" for="mr-description" class="detail-field-label"
                      >Description</Label
                    >
                    <span v-else class="detail-field-label">Description</span>
                    <p v-if="!editing" class="detail-field-value detail-field-prose">
                      {{ record.description ?? 'No description provided.' }}
                    </p>
                    <Textarea
                      v-else
                      id="mr-description"
                      v-model="draft.description"
                      :rows="5"
                      placeholder="Describe the fault, symptoms, or maintenance needed…"
                    />
                    <p v-if="editing && validationErrors?.description" class="form-error">
                      {{ validationErrors.description[0] }}
                    </p>
                  </div>
                </div>

                <!-- Preventive trigger info (role-gated by presence) -->
                <div v-if="record.is_preventive" class="detail-section">
                  <h3 class="detail-section-title">Preventive trigger</h3>
                  <div class="detail-grid">
                    <div v-if="record.triggered_by_date" class="detail-field">
                      <span class="detail-field-label">Triggered by date</span>
                      <p class="detail-field-value">Yes</p>
                    </div>
                    <div v-if="record.trigger_date" class="detail-field">
                      <span class="detail-field-label">Trigger date</span>
                      <p class="detail-field-value">{{ fmtDate(record.trigger_date) }}</p>
                    </div>
                    <div v-if="record.triggered_by_reading" class="detail-field">
                      <span class="detail-field-label">Triggered by reading</span>
                      <p class="detail-field-value">Yes</p>
                    </div>
                    <div v-if="record.trigger_reading_value" class="detail-field">
                      <span class="detail-field-label">Trigger reading</span>
                      <p class="detail-field-value">{{ record.trigger_reading_value }}</p>
                    </div>
                  </div>
                </div>

                <!-- Rejection reason (role-gated by presence) -->
                <div v-if="record.rejection_reason" class="detail-section">
                  <h3 class="detail-section-title">Rejection reason</h3>
                  <div class="detail-callout detail-callout-destructive">
                    <p class="detail-field-value detail-field-prose">
                      {{ record.rejection_reason }}
                    </p>
                  </div>
                </div>

                <!-- Cancellation reason (role-gated by presence) -->
                <div v-if="record.cancellation_reason" class="detail-section">
                  <h3 class="detail-section-title">Cancellation reason</h3>
                  <div class="detail-callout">
                    <p class="detail-field-value detail-field-prose">
                      {{ record.cancellation_reason }}
                    </p>
                  </div>
                </div>

                <!-- Resulting Work Order -->
                <div v-if="record.work_order" class="detail-section">
                  <h3 class="detail-section-title">Resulting work order</h3>
                  <RouterLink :to="`/work-orders/${record.work_order.id}`" class="table-link">
                    {{ record.work_order.number }}
                  </RouterLink>
                </div>
              </div>
            </div>
          </div>

          <aside class="detail-rail">
            <!-- Attachments -->
            <div class="data-card">
              <div class="data-card-header">
                <h2 class="data-card-title">Attachments</h2>
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
                            v-if="canDeleteAttachment(a)"
                            variant="ghost"
                            size="icon-sm"
                            class="attachment-delete"
                            :title="`Delete ${a.file_name}`"
                            :aria-label="`Delete ${a.file_name}`"
                            @click="openDeleteAttachment(a)"
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

    <!-- Delete attachment confirmation -->
    <Dialog
      :open="!!deleteAttachmentTarget"
      @update:open="
        (v) => {
          if (!v) closeDeleteAttachment()
        }
      "
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete attachment?</DialogTitle>
          <DialogDescription>
            <strong>{{ deleteAttachmentTarget?.file_name }}</strong> will be permanently removed
            from this request. This cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button
            variant="outline"
            :disabled="deleteAttachmentLoading"
            @click="closeDeleteAttachment"
            >Back</Button
          >
          <Button
            variant="destructive"
            :disabled="deleteAttachmentLoading"
            @click="doDeleteAttachment"
          >
            {{ deleteAttachmentLoading ? 'Deleting…' : 'Delete Attachment' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Approve confirmation -->
    <Dialog v-model:open="approveOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Approve request {{ record?.number }}?</DialogTitle>
          <DialogDescription>
            Approving converts this request into a Work Order. This cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <div v-if="isCorrective" class="form-field">
          <Label for="approve-failure"
            >Is this a failure? <span class="field-required">*</span></Label
          >
          <Select v-model="approveIsFailureStr">
            <SelectTrigger id="approve-failure"
              ><SelectValue placeholder="Classify this request"
            /></SelectTrigger>
            <SelectContent>
              <SelectItem value="failure">Yes — a genuine failure</SelectItem>
              <SelectItem value="no_failure">No — not a failure</SelectItem>
            </SelectContent>
          </Select>
          <p class="form-help">Required for corrective requests — used in the MTBF metric.</p>
        </div>
        <div class="form-field">
          <Label for="approve-tech">Assign to <span class="field-optional">— optional</span></Label>
          <div v-if="approveTechniciansLoading" class="loading-state">Loading assignees…</div>
          <Select v-else v-model="selectedApproveTechIdStr">
            <SelectTrigger id="approve-tech"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="__none__">Leave unassigned</SelectItem>
              <SelectItem v-for="t in approveTechnicians" :key="t.id" :value="String(t.id)">
                {{ t.name }} <span class="select-item-meta">{{ roleLabel(t.role) }}</span>
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="approveLoading" @click="approveOpen = false"
            >Back</Button
          >
          <Button
            :disabled="approveLoading || (isCorrective && approveIsFailure === null)"
            @click="doApprove"
          >
            {{ approveLoading ? 'Approving…' : 'Approve & Create Work Order' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Reject confirmation -->
    <Dialog v-model:open="rejectOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Reject request {{ record?.number }}?</DialogTitle>
          <DialogDescription>A reason is required. This cannot be undone.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="reject-reason">Reason</Label>
          <Textarea
            id="reject-reason"
            v-model="rejectReason"
            :rows="4"
            placeholder="Explain why this request is rejected…"
          />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="rejectLoading" @click="rejectOpen = false"
            >Back</Button
          >
          <Button :disabled="rejectLoading || !rejectReason.trim()" @click="doReject">
            {{ rejectLoading ? 'Rejecting…' : 'Reject Request' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Cancel confirmation -->
    <Dialog v-model:open="cancelOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Cancel request {{ record?.number }}?</DialogTitle>
          <DialogDescription>A reason is required. This cannot be undone.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="cancel-reason">Reason</Label>
          <Textarea
            id="cancel-reason"
            v-model="cancelReason"
            :rows="4"
            placeholder="Explain why this request is cancelled…"
          />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="cancelLoading" @click="cancelOpen = false"
            >Back</Button
          >
          <Button :disabled="cancelLoading || !cancelReason.trim()" @click="doCancel">
            {{ cancelLoading ? 'Cancelling…' : 'Cancel Request' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
