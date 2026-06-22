<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import { Button } from '@/components/ui/button'
import { FileInput } from '@/components/ui/file-input'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle,
  DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { useAuthStore } from '@/stores/auth.store'
import { useMaintenanceRequests } from '@/composables/useMaintenanceRequests'
import {
  mrStatusClass, mrStatusLabel, priorityClass, priorityLabel, mrTypeLabel, fmtDate, formatBytes,
} from '@/lib/displayHelpers'

const auth   = useAuthStore()
const route  = useRoute()
const router = useRouter()

const {
  allMr, loadAllRequests,
  ar, loadAwaiting,
  mr, loadMyRequests,
  approveTarget, approveOpen, approveLoading, openApprove, doApprove,
  rejectTarget, rejectOpen, rejectLoading, rejectReason, openReject, doReject,
  cancelTarget, cancelOpen, cancelLoading, cancelReason, openCancel, doCancel,
  assetSearch,
  createOpen, confirmCreateOpen, createLoading, createPriority, createDescription,
  attachFiles, addFiles, removeFile,
  canCreate,
  requestCreate, doCreate, closeCreate,
} = useMaintenanceRequests()

const fileInput = ref<InstanceType<typeof FileInput> | null>(null)

// ── Handle ?action=new from sidebar "New Request" link ────────────────────────

watch(() => route.query.action, (action) => {
  if (action === 'new') {
    createOpen.value = true
    router.replace({ path: route.path, query: { tab: activeTab.value } })
  }
}, { immediate: true })

// ── Tabs ──────────────────────────────────────────────────────────────────────

const tabDefs = computed(() => {
  const t: { key: string; label: string }[] = []
  if (auth.isAdminOrManager) t.push({ key: 'pending-approval', label: 'Pending Approval' })
  t.push({ key: 'my-requests', label: 'My Requests' })
  if (auth.isAdminOrManager) t.push({ key: 'all-requests', label: 'All Requests' })
  return t
})

const activeTab = computed(() => {
  const t = route.query.tab as string | undefined
  return tabDefs.value.some(d => d.key === t) ? t! : (tabDefs.value[0]?.key ?? '')
})

watch(activeTab, (tab) => {
  if (tab && route.query.tab !== tab) router.replace({ path: route.path, query: { tab } })
  if (!tab) return
  if (tab === 'pending-approval') loadAwaiting()
  if (tab === 'my-requests')     loadMyRequests()
  if (tab === 'all-requests')    loadAllRequests()
}, { immediate: true })
</script>

<template>
  <AppLayout>
    <div class="page-section">

      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Maintenance Requests</h1>
          <p class="page-subtitle">Submit and track corrective and preventive maintenance requests</p>
        </div>
        <div class="page-actions">
          <Button v-if="canCreate" @click="createOpen = true">New Request</Button>
        </div>
      </div>

      <div class="view-tabs">
        <RouterLink
          v-for="tab in tabDefs"
          :key="tab.key"
          :to="{ path: '/maintenance', query: { tab: tab.key } }"
          :class="['view-tab', activeTab === tab.key ? 'view-tab-active' : 'view-tab-normal']"
        >
          {{ tab.label }}
          <span v-if="tab.key === 'pending-approval' && ar.items.value.length > 0" class="view-tab-count">
            {{ ar.items.value.length }}
          </span>
        </RouterLink>
      </div>

      <!-- ── Pending Approval ── -->
      <template v-if="activeTab === 'pending-approval'">
        <div v-if="ar.loading.value" class="loading-state">Loading…</div>
        <div v-else-if="ar.error.value" class="error-state">{{ ar.error.value }}</div>
        <template v-else>
          <div v-if="ar.items.value.length === 0" class="empty-state">No requests awaiting approval.</div>
          <div v-else class="table-container">
            <table class="dense-table">
              <thead>
                <tr>
                  <th>#</th><th>Type</th><th>Asset</th><th>Priority</th>
                  <th>Description</th><th>Submitted By</th><th>Date</th><th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in ar.items.value" :key="item.id">
                  <td><RouterLink :to="`/maintenance/requests/${item.id}`" class="table-link">{{ item.number }}</RouterLink></td>
                  <td>{{ mrTypeLabel(item.type) }}</td>
                  <td>
                    <div class="table-cell-primary">{{ item.asset.name }}</div>
                    <div class="table-cell-secondary">{{ item.asset.erp_asset_code }}</div>
                  </td>
                  <td><span :class="priorityClass(item.priority)">{{ priorityLabel(item.priority) }}</span></td>
                  <td class="table-cell-truncate">{{ item.description ?? '—' }}</td>
                  <td>{{ item.created_by?.name ?? '—' }}</td>
                  <td>{{ fmtDate(item.created_at) }}</td>
                  <td class="table-cell-actions">
                    <Button size="sm" @click="openApprove(item)">Approve</Button>
                    <Button size="sm" variant="outline" @click="openReject(item)">Reject</Button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="ar.nextCursor.value" class="load-more-row">
            <Button variant="outline" size="sm" :disabled="ar.loadingMore.value" @click="loadAwaiting(true)">
              {{ ar.loadingMore.value ? 'Loading…' : 'Load more' }}
            </Button>
          </div>
        </template>
      </template>

      <!-- ── My Requests ── -->
      <template v-else-if="activeTab === 'my-requests'">
        <div v-if="mr.loading.value" class="loading-state">Loading…</div>
        <div v-else-if="mr.error.value" class="error-state">{{ mr.error.value }}</div>
        <template v-else>
          <div v-if="mr.items.value.length === 0" class="empty-state">You haven't submitted any requests yet.</div>
          <div v-else class="table-container">
            <table class="dense-table">
              <thead>
                <tr>
                  <th>#</th><th>Type</th><th>Asset</th><th>Priority</th>
                  <th>Status</th><th>Date</th><th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in mr.items.value" :key="item.id">
                  <td><RouterLink :to="`/maintenance/requests/${item.id}`" class="table-link">{{ item.number }}</RouterLink></td>
                  <td>{{ mrTypeLabel(item.type) }}</td>
                  <td>
                    <div class="table-cell-primary">{{ item.asset.name }}</div>
                    <div class="table-cell-secondary">{{ item.asset.erp_asset_code }}</div>
                  </td>
                  <td><span :class="priorityClass(item.priority)">{{ priorityLabel(item.priority) }}</span></td>
                  <td><span :class="mrStatusClass(item.status)">{{ mrStatusLabel(item.status) }}</span></td>
                  <td>{{ fmtDate(item.created_at) }}</td>
                  <td class="table-cell-actions">
                    <Button v-if="item.status === 'pending_review'" size="sm" variant="outline" @click="openCancel(item)">
                      Cancel
                    </Button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="mr.nextCursor.value" class="load-more-row">
            <Button variant="outline" size="sm" :disabled="mr.loadingMore.value" @click="loadMyRequests(true)">
              {{ mr.loadingMore.value ? 'Loading…' : 'Load more' }}
            </Button>
          </div>
        </template>
      </template>

      <!-- ── All Requests ── -->
      <template v-else-if="activeTab === 'all-requests'">
        <div v-if="allMr.loading.value" class="loading-state">Loading…</div>
        <div v-else-if="allMr.error.value" class="error-state">{{ allMr.error.value }}</div>
        <template v-else>
          <div v-if="allMr.items.value.length === 0" class="empty-state">No maintenance requests found.</div>
          <div v-else class="table-container">
            <table class="dense-table">
              <thead>
                <tr>
                  <th>#</th><th>Type</th><th>Asset</th><th>Priority</th>
                  <th>Status</th><th>Submitted By</th><th>Date</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="item in allMr.items.value"
                  :key="item.id"
                  class="table-row-clickable"
                  @click="router.push(`/maintenance/requests/${item.id}`)"
                >
                  <td class="table-cell-mono">{{ item.number }}</td>
                  <td>{{ mrTypeLabel(item.type) }}</td>
                  <td>
                    <div class="table-cell-primary">{{ item.asset.name }}</div>
                    <div class="table-cell-secondary">{{ item.asset.erp_asset_code }}</div>
                  </td>
                  <td><span :class="priorityClass(item.priority)">{{ priorityLabel(item.priority) }}</span></td>
                  <td><span :class="mrStatusClass(item.status)">{{ mrStatusLabel(item.status) }}</span></td>
                  <td>{{ item.created_by?.name ?? '—' }}</td>
                  <td>{{ fmtDate(item.created_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="allMr.nextCursor.value" class="load-more-row">
            <Button variant="outline" size="sm" :disabled="allMr.loadingMore.value" @click="loadAllRequests(true)">
              {{ allMr.loadingMore.value ? 'Loading…' : 'Load more' }}
            </Button>
          </div>
        </template>
      </template>

    </div>
  </AppLayout>

  <!-- ── Approve dialog ── -->
  <Dialog v-model:open="approveOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Approve Request</DialogTitle>
        <DialogDescription>
          Approving <strong>{{ approveTarget?.number }}</strong> will create a work order immediately. This cannot be undone.
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" :disabled="approveLoading" @click="approveOpen = false">Cancel</Button>
        <Button :disabled="approveLoading" @click="doApprove">
          {{ approveLoading ? 'Approving…' : 'Approve & Create Work Order' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- ── Reject dialog ── -->
  <Dialog v-model:open="rejectOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Reject Request</DialogTitle>
        <DialogDescription>Provide a reason for rejecting <strong>{{ rejectTarget?.number }}</strong>.</DialogDescription>
      </DialogHeader>
      <div class="dialog-field">
        <Label for="reject-reason">Reason</Label>
        <Textarea id="reject-reason" v-model="rejectReason" placeholder="Explain why this request is being rejected…" rows="3" />
      </div>
      <DialogFooter>
        <Button variant="outline" :disabled="rejectLoading" @click="rejectOpen = false">Cancel</Button>
        <Button variant="destructive" :disabled="rejectLoading || !rejectReason.trim()" @click="doReject">
          {{ rejectLoading ? 'Rejecting…' : 'Reject Request' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- ── Cancel dialog ── -->
  <Dialog v-model:open="cancelOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Cancel Request</DialogTitle>
        <DialogDescription>Provide a reason for cancelling <strong>{{ cancelTarget?.number }}</strong>.</DialogDescription>
      </DialogHeader>
      <div class="dialog-field">
        <Label for="cancel-reason">Reason</Label>
        <Textarea id="cancel-reason" v-model="cancelReason" placeholder="Why are you cancelling this request?" rows="3" />
      </div>
      <DialogFooter>
        <Button variant="outline" :disabled="cancelLoading" @click="cancelOpen = false">Back</Button>
        <Button variant="destructive" :disabled="cancelLoading || !cancelReason.trim()" @click="doCancel">
          {{ cancelLoading ? 'Cancelling…' : 'Cancel Request' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- ── Create MR sheet ── -->
  <Sheet :open="createOpen" @update:open="(v) => { if (!v) closeCreate() }">
    <SheetContent side="right" class="create-sheet">
      <SheetHeader class="create-sheet-header">
        <SheetTitle>New Maintenance Request</SheetTitle>
        <SheetDescription>Submit a corrective maintenance request for an asset.</SheetDescription>
      </SheetHeader>
      <div class="create-sheet-body">
        <div class="form-field">
          <Label for="asset-search">Asset <span class="field-required">*</span></Label>
          <div class="asset-search-wrap">
            <Input id="asset-search" v-model="assetSearch.query.value" placeholder="Search by asset name or ERP code…" autocomplete="off" @input="assetSearch.onInput" />
            <div v-if="assetSearch.results.value.length > 0" class="asset-search-results">
              <div v-for="a in assetSearch.results.value" :key="a.id" class="asset-search-item" @click="assetSearch.select(a)">
                <div class="asset-search-item-name">{{ a.name }}</div>
                <div class="asset-search-item-code">{{ a.erp_asset_code }}</div>
              </div>
            </div>
            <div v-else-if="assetSearch.busy.value" class="asset-search-results">
              <div class="asset-search-item">Searching…</div>
            </div>
          </div>
          <p v-if="assetSearch.selected.value" class="form-field-hint form-field-hint-selected">
            ✓ {{ assetSearch.selected.value.label }}
          </p>
        </div>
        <div class="form-field">
          <Label for="priority">Priority <span class="field-required">*</span></Label>
          <Select v-model="createPriority">
            <SelectTrigger id="priority"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="low">Low</SelectItem>
              <SelectItem value="medium">Medium</SelectItem>
              <SelectItem value="high">High</SelectItem>
              <SelectItem value="critical">Critical — immediate attention required</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="form-field">
          <Label for="description">Description <span class="field-optional">— optional</span></Label>
          <Textarea id="description" v-model="createDescription" placeholder="Describe the fault, symptoms, or maintenance needed…" :rows="5" />
        </div>
        <div class="form-field">
          <Label for="file-pick">Attachments <span class="field-optional">— optional</span></Label>
          <Button id="file-pick" type="button" variant="outline" class="file-pick-btn" @click="fileInput?.open()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
            Attach files (PDF, images, Office — max 20 MB each)
          </Button>
          <FileInput ref="fileInput" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx" @change="addFiles" />
          <ul v-if="attachFiles.length > 0" class="file-list">
            <li v-for="(f, i) in attachFiles" :key="i" class="file-list-item">
              <span class="file-list-name">{{ f.name }}</span>
              <span class="file-list-size">{{ formatBytes(f.size) }}</span>
              <Button type="button" variant="ghost" size="icon" class="file-list-remove" aria-label="Remove attachment" @click="removeFile(i)">✕</Button>
            </li>
          </ul>
        </div>
      </div>
      <div class="create-sheet-footer">
        <Button variant="outline" :disabled="createLoading" @click="closeCreate">Cancel</Button>
        <Button :disabled="createLoading || !assetSearch.selected.value" @click="requestCreate">Create Request</Button>
      </div>
    </SheetContent>
  </Sheet>

  <!-- ── Confirm Create dialog ── -->
  <Dialog v-model:open="confirmCreateOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Create Maintenance Request</DialogTitle>
        <DialogDescription>
          Submit a corrective request for <strong>{{ assetSearch.selected.value?.label }}</strong> with <strong>{{ createPriority }}</strong> priority?
          <template v-if="attachFiles.length > 0"> {{ attachFiles.length }} file{{ attachFiles.length !== 1 ? 's' : '' }} will be attached.</template>
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" :disabled="createLoading" @click="confirmCreateOpen = false">Back</Button>
        <Button :disabled="createLoading" @click="doCreate">
          {{ createLoading ? 'Submitting…' : 'Create Request' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
