<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import AppDataTable from '@/components/app/AppDataTable.vue'
import AssetCombobox from '@/components/app/AssetCombobox.vue'
import { Button } from '@/components/ui/button'
import { FileInput } from '@/components/ui/file-input'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle,
  DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { PaperclipIcon } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.store'
import { useMaintenanceRequests } from '@/composables/useMaintenanceRequests'
import { useListOptions } from '@/composables/useListOptions'
import { mrColumns, mrFilterOptions } from '@/lib/mrColumns'
import type { MaintenanceRequest } from '@/types'
import {
  mrStatusClass, mrStatusLabel, priorityClass, priorityLabel, priorityPickerLabel, mrTypeLabel, fmtDate, formatBytes,
} from '@/lib/displayHelpers'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()

const {
  myRequests, awaiting, allRequests,
  selectedAsset,
  createOpen, confirmCreateOpen, createLoading, createPriority, createDescription,
  attachFiles, addFiles, removeFile,
  canCreate,
  requestCreate, doCreate, closeCreate,
} = useMaintenanceRequests()

const { priorities, loadPriorities } = useListOptions()
loadPriorities()

// Static filter options + the live priorities list (Admin-configurable).
const mergedFilterOptions = computed(() => ({ ...mrFilterOptions, priority: priorities.value }))

const fileInput = ref<InstanceType<typeof FileInput> | null>(null)

// ── Tabs ──────────────────────────────────────────────────────────────────────

const tabDefs = computed(() => {
  const t: { key: string; label: string }[] = []
  t.push({ key: 'my-requests', label: 'My Requests' })
  if (auth.isAdminOrManager) t.push({ key: 'pending-approval', label: 'Pending Approval' })
  if (auth.isAdminOrManager) t.push({ key: 'all-requests', label: 'All Requests' })
  return t
})

const activeTab = computed(() => {
  const t = route.query.tab as string | undefined
  return tabDefs.value.some(d => d.key === t) ? t! : (tabDefs.value[0]?.key ?? '')
})

// Active tab's data slice (rows + loading + loader + copy), derived per tab.
const activeSlice = computed(() => {
  if (activeTab.value === 'pending-approval') return {
    rows: awaiting.rows.value, loading: awaiting.loading.value, load: awaiting.load,
    emptyText: 'No requests awaiting approval.', label: 'Requests awaiting approval',
  }
  if (activeTab.value === 'all-requests') return {
    rows: allRequests.rows.value, loading: allRequests.loading.value, load: allRequests.load,
    emptyText: 'No maintenance requests found.', label: 'All maintenance requests',
  }
  return {
    rows: myRequests.rows.value, loading: myRequests.loading.value, load: myRequests.load,
    emptyText: "You haven't submitted any requests yet.", label: 'My maintenance requests',
  }
})

watch(activeTab, (tab) => {
  if (tab && route.query.tab !== tab) router.replace({ path: route.path, query: { tab } })
})
watch(activeTab, () => { activeSlice.value.load() }, { immediate: true })

// ── Handle ?action=new from sidebar "New Request" link ────────────────────────

watch(() => route.query.action, (action) => {
  if (action === 'new') {
    createOpen.value = true
    router.replace({ path: route.path, query: { tab: activeTab.value } })
  }
}, { immediate: true })

function goDetail(payload: { row: MaintenanceRequest }) {
  router.push(`/maintenance/requests/${payload.row.id}`)
}
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
        </RouterLink>
      </div>

      <AppDataTable
        :key="activeTab"
        :rows="activeSlice.rows"
        :columns="mrColumns"
        :filter-options="mergedFilterOptions"
        :empty-text="activeSlice.emptyText"
        :loading="activeSlice.loading"
        :label="activeSlice.label"
        @row-click="goDetail"
      >
        <template #cell="{ column, row, value }">
          <RouterLink
            v-if="column.field === 'number'"
            :to="`/maintenance/requests/${row.id}`"
            class="table-link"
          >{{ row.number }}</RouterLink>

          <span v-else-if="column.field === 'asset'" class="table-cell-stack">
            <span class="table-cell-primary">{{ row.asset?.name }}</span>
            <span class="table-cell-secondary">{{ row.asset?.erp_asset_code }}</span>
          </span>

          <span v-else-if="column.field === 'priority'" :class="priorityClass(row.priority)">
            {{ priorityLabel(row.priority) }}
          </span>

          <span v-else-if="column.field === 'status'" :class="mrStatusClass(row.status)">
            {{ mrStatusLabel(row.status) }}
          </span>

          <span v-else-if="column.field === 'type'">{{ mrTypeLabel(row.type) }}</span>

          <span v-else-if="column.field === 'created_at'">{{ fmtDate(row.created_at) }}</span>

          <span v-else-if="column.field === 'description'" class="table-cell-truncate">
            {{ row.description ?? '—' }}
          </span>

          <template v-else>{{ value }}</template>
        </template>
      </AppDataTable>

    </div>
  </AppLayout>

  <!-- ── Create MR sheet ── -->
  <!-- modal=false: a modal Sheet runs useHideOthers + a focus trap +
       disableOutsidePointerEvents, which together break ANY portaled widget
       opened inside it (the AssetCombobox popover AND the Priority Select) —
       the popover gets aria-hidden by useHideOthers and can't receive focus.
       Non-modal keeps the overlay/X/Esc close behavior while letting those
       dropdowns work. See docs/04-frontend/Issues.md. -->
  <Sheet :open="createOpen" :modal="false" @update:open="(v) => { if (!v) closeCreate() }">
    <SheetContent side="right" class="create-sheet">
      <SheetHeader class="create-sheet-header">
        <SheetTitle>New Maintenance Request</SheetTitle>
        <SheetDescription>Submit a corrective maintenance request for an asset.</SheetDescription>
      </SheetHeader>
      <div class="create-sheet-body">
        <div class="form-field">
          <Label for="asset">Asset <span class="field-required">*</span></Label>
          <AssetCombobox v-model="selectedAsset" />
        </div>
        <div class="form-field">
          <Label for="priority">Priority <span class="field-required">*</span></Label>
          <Select v-model="createPriority">
            <SelectTrigger id="priority"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="opt in priorities"
                :key="opt.value"
                :value="opt.value"
              >{{ priorityPickerLabel(opt) }}</SelectItem>
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
            <PaperclipIcon class="icon-sm" />
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
        <Button :disabled="createLoading || !selectedAsset" @click="requestCreate">Create Request</Button>
      </div>
    </SheetContent>
  </Sheet>

  <!-- ── Confirm Create dialog ── -->
  <Dialog v-model:open="confirmCreateOpen">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Create Maintenance Request</DialogTitle>
        <DialogDescription>
          Submit a corrective request for <strong>{{ selectedAsset?.label }}</strong> with <strong>{{ createPriority }}</strong> priority?
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
