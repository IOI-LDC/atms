<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { useMaintenanceRequestDetail } from '@/composables/useMaintenanceRequestDetail'
import {
  mrStatusClass, mrStatusLabel, priorityClass, priorityLabel, mrTypeLabel, fmtDate, formatBytes,
} from '@/lib/displayHelpers'

const route = useRoute()
const id = computed(() => Number(route.params.requestId))

const {
  record, loading, error, notFound, forbidden,
  editing, saving, editError, draft, validationErrors,
  attachments, attachmentsLoading,
  isTerminal, canEdit, canApprove, canReject, canCancel,
  load, startEdit, cancelEdit, saveEdit,
  approveOpen, approveLoading, openApprove, doApprove,
  rejectOpen, rejectLoading, rejectReason, openReject, doReject,
  cancelOpen, cancelLoading, cancelReason, openCancel, doCancel,
} = useMaintenanceRequestDetail()

watch(id, (newId) => { if (newId) load(newId) }, { immediate: true })
</script>

<template>
  <AppLayout>
    <div class="page-section">

      <!-- Load states -->
      <div v-if="loading" class="loading-state">Loading request…</div>
      <div v-else-if="notFound" class="empty-state">Maintenance request not found.</div>
      <div v-else-if="forbidden" class="permission-state">You don't have permission to view this request.</div>
      <div v-else-if="error" class="error-state" role="alert">{{ error }}</div>

      <template v-else-if="record">
        <!-- Header -->
        <div class="page-header">
          <div class="page-heading">
            <h1 class="page-title">{{ record.number }}</h1>
            <p class="page-subtitle">{{ mrTypeLabel(record.type) }} maintenance request</p>
          </div>
          <div class="page-actions">
            <span :class="mrStatusClass(record.status)">{{ mrStatusLabel(record.status) }}</span>
            <span :class="priorityClass(record.priority)">{{ priorityLabel(record.priority) }}</span>
          </div>
        </div>

        <!-- Read-only banner for terminal statuses -->
        <div v-if="isTerminal" class="read-only-state">
          This request is {{ mrStatusLabel(record.status) }} and can no longer be changed.
        </div>

        <!-- Details card -->
        <div class="data-card">
          <div class="data-card-header">
            <div class="data-card-title">Details</div>
            <div class="data-card-actions">
              <Button v-if="canEdit && !editing" size="sm" variant="outline" @click="startEdit">Edit</Button>
              <Button v-if="editing" size="sm" variant="outline" :disabled="saving" @click="cancelEdit">Cancel</Button>
              <Button v-if="editing" size="sm" :disabled="saving" @click="saveEdit">
                {{ saving ? 'Saving…' : 'Save Changes' }}
              </Button>
            </div>
          </div>
          <div class="data-card-content">

            <div v-if="editError" class="error-state" role="alert">{{ editError }}</div>

            <!-- Meta -->
            <dl class="detail-meta">
              <div>
                <dt>Asset</dt>
                <dd>{{ record.asset.name }} <span class="table-cell-secondary">({{ record.asset.erp_asset_code }})</span></dd>
              </div>
              <div>
                <dt>Requested by</dt>
                <dd>{{ record.created_by?.name ?? '—' }}</dd>
              </div>
              <div>
                <dt>Created</dt>
                <dd>{{ fmtDate(record.created_at) }}</dd>
              </div>
              <div>
                <dt>Reviewed by</dt>
                <dd>{{ record.reviewed_by?.name ?? '—' }}</dd>
              </div>
            </dl>

            <!-- Description (read / edit) -->
            <div class="form-field">
              <Label for="mr-description">Description</Label>
              <p v-if="!editing" class="detail-text">{{ record.description ?? 'No description provided.' }}</p>
              <Textarea
                v-else id="mr-description" v-model="draft.description" :rows="5"
                placeholder="Describe the fault, symptoms, or maintenance needed…"
              />
              <p v-if="editing && validationErrors?.description" class="form-error">
                {{ validationErrors.description[0] }}
              </p>
            </div>

            <!-- Priority (read / edit) -->
            <div class="form-field">
              <Label for="mr-priority">Priority</Label>
              <span v-if="!editing" :class="priorityClass(record.priority)">{{ priorityLabel(record.priority) }}</span>
              <Select v-else v-model="draft.priority">
                <SelectTrigger id="mr-priority"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="low">Low</SelectItem>
                  <SelectItem value="medium">Medium</SelectItem>
                  <SelectItem value="high">High</SelectItem>
                  <SelectItem value="critical">Critical — immediate attention required</SelectItem>
                </SelectContent>
              </Select>
              <p v-if="editing && validationErrors?.priority" class="form-error">
                {{ validationErrors.priority[0] }}
              </p>
            </div>

            <!-- Preventive trigger info (role-gated by presence) -->
            <div v-if="record.is_preventive" class="detail-section">
              <h2 class="data-card-title">Preventive trigger</h2>
              <dl class="detail-meta">
                <div v-if="record.triggered_by_date">
                  <dt>Triggered by date</dt><dd>Yes</dd>
                </div>
                <div v-if="record.trigger_date">
                  <dt>Trigger date</dt><dd>{{ fmtDate(record.trigger_date) }}</dd>
                </div>
                <div v-if="record.triggered_by_reading">
                  <dt>Triggered by reading</dt><dd>Yes</dd>
                </div>
                <div v-if="record.trigger_reading_value">
                  <dt>Trigger reading</dt><dd>{{ record.trigger_reading_value }}</dd>
                </div>
              </dl>
            </div>

            <!-- Rejection reason (role-gated by presence) -->
            <div v-if="record.rejection_reason" class="detail-section">
              <h2 class="data-card-title">Rejection reason</h2>
              <p class="detail-text">{{ record.rejection_reason }}</p>
            </div>

            <!-- Cancellation reason (role-gated by presence) -->
            <div v-if="record.cancellation_reason" class="detail-section">
              <h2 class="data-card-title">Cancellation reason</h2>
              <p class="detail-text">{{ record.cancellation_reason }}</p>
            </div>

            <!-- Resulting Work Order -->
            <div v-if="record.work_order" class="detail-section">
              <h2 class="data-card-title">Resulting work order</h2>
              <RouterLink :to="`/work-orders/${record.work_order.id}`" class="table-link">
                {{ record.work_order.number }}
              </RouterLink>
            </div>

          </div>
        </div>

        <!-- Attachments -->
        <div class="data-card">
          <div class="data-card-header"><div class="data-card-title">Attachments</div></div>
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
        <div v-if="canApprove || canReject || canCancel" class="detail-actions">
          <Button v-if="canCancel" variant="outline" @click="openCancel">Cancel Request</Button>
          <Button v-if="canReject" variant="outline" @click="openReject">Reject Request</Button>
          <Button v-if="canApprove" @click="openApprove">Approve &amp; Create Work Order</Button>
        </div>
      </template>

    </div>

    <!-- Approve confirmation -->
    <Dialog v-model:open="approveOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Approve request {{ record?.number }}?</DialogTitle>
          <DialogDescription>
            Approving converts this request into a Work Order. This cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" :disabled="approveLoading" @click="approveOpen = false">Back</Button>
          <Button :disabled="approveLoading" @click="doApprove">
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
          <Textarea id="reject-reason" v-model="rejectReason" :rows="4" placeholder="Explain why this request is rejected…" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="rejectLoading" @click="rejectOpen = false">Back</Button>
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
          <Textarea id="cancel-reason" v-model="cancelReason" :rows="4" placeholder="Explain why this request is cancelled…" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="cancelLoading" @click="cancelOpen = false">Back</Button>
          <Button :disabled="cancelLoading || !cancelReason.trim()" @click="doCancel">
            {{ cancelLoading ? 'Cancelling…' : 'Cancel Request' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

  </AppLayout>
</template>
