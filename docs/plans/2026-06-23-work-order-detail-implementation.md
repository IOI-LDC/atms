# Work Order Detail Page — Implementation Plan

> **For Kilo:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the placeholder `WorkOrderDetailView.vue` with a full-lifecycle Work Order execution and closure page: assign, start, complete, close, cancel, inline parts-used management, meter reading recording, asset status updates, and attachment upload.

**Architecture:** A single-instance composable (`useWorkOrderDetail.ts`) owns all state + actions, following the exact pattern established by `useMaintenanceRequestDetail.ts`. The `.vue` view is pure orchestration — it imports the composable, watches the route param to trigger load, and renders cards using existing semantic classes. The parts picker reuses the `AssetCombobox` searchable-dropdown pattern. Attachment upload reuses the `FileInput` component and `file-list` pattern from the create-MR Sheet.

**Tech Stack:** Vue 3.5 (`<script setup>` + `<script lang="ts">` for imports), TypeScript, Vue Router, shadcn-vue primitives (Button, Input, Label, Textarea, Select, Dialog, AlertDialog, FileInput), vue-sonner (toast), `@ioi-dev/vue-table` types only.

**Design doc:** `docs/plans/2026-06-23-work-order-detail-design.md`

**Backend prerequisites:** Two backend changes must ship before or alongside this plan — see the design doc section "Backend prerequisites."
1. `UserPolicy::viewAny` broadened to Admin + Manager.
2. New `POST /api/work-orders/{workOrder}/asset-status` endpoint.

**Verification note:** No frontend test runner exists. Verification for each task is `npm run type-check` (vue-tsc) against the modified file(s), then manual smoke check in the dev server (`http://localhost:5173/work-orders/:id`) after the view task.

---

### Task 1: Composable — scaffolding, load, and permissions

**Files:**
- Create: `frontend/src/composables/useWorkOrderDetail.ts`
- Reference: `frontend/src/composables/useMaintenanceRequestDetail.ts` (pattern to mirror)
- Reference: `frontend/src/lib/api.ts` (API client)
- Reference: `frontend/src/stores/auth.store.ts` (auth role flags)
- Reference: `frontend/src/types/index.ts` (WorkOrder, WorkOrderStatus, Priority, WorkOrderPart, UserRef)

**Step 1: Scaffold the composable with load function and permission computeds**

Create `frontend/src/composables/useWorkOrderDetail.ts`:

```typescript
import { ref, computed } from 'vue'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'
import type { WorkOrder, WorkOrderPart, Priority, UserRef, Attachment } from '@/types'

export function useWorkOrderDetail() {
  const auth = useAuthStore()

  // ── Record + load state ──────────────────────────────────────────────────
  const record    = ref<WorkOrder | null>(null)
  const loading   = ref(false)
  const error     = ref<string | null>(null)
  const notFound  = ref(false)
  const forbidden = ref(false)

  // ── Attachments ──────────────────────────────────────────────────────────
  const attachments        = ref<Attachment[]>([])
  const attachmentsLoading = ref(false)

  // ── Derived ──────────────────────────────────────────────────────────────
  const isOpen       = computed(() => record.value?.status === 'open')
  const isInProgress = computed(() => record.value?.status === 'in_progress')
  const isCompleted  = computed(() => record.value?.status === 'completed')
  const isTerminal   = computed(() => !!record.value && (record.value.status === 'closed' || record.value.status === 'cancelled'))

  const isAssignedToMe = computed(() =>
    !!record.value?.assigned_to && record.value.assigned_to.id === auth.user?.id
  )

  // Permissions (client UX hints — backend gate remains authoritative)
  const canEdit   = computed(() => !!record.value && !isTerminal.value && !isCompleted.value && (auth.isAdminOrManager || isAssignedToMe.value))
  const canAssign = computed(() => !!record.value && isOpen.value && auth.isAdminOrManager)
  const canStart  = computed(() => !!record.value && isOpen.value && !!record.value.assigned_to && (auth.isAdminOrManager || isAssignedToMe.value))
  const canComplete = computed(() => !!record.value && isInProgress.value && (auth.isAdminOrManager || isAssignedToMe.value))
  const canClose  = computed(() => !!record.value && isCompleted.value && auth.isAdminOrManager)
  const canCancel = computed(() => !!record.value && !isTerminal.value && auth.isAdminOrManager)
  const canSetAssetStatus = computed(() => !!record.value && !isTerminal.value && (auth.isAdminOrManager || isAssignedToMe.value))

  // ── Load ─────────────────────────────────────────────────────────────────
  async function load(id: number | string) {
    loading.value = true
    error.value = null
    notFound.value = false
    forbidden.value = false
    try {
      const res = await api.get<{ data: WorkOrder }>(`/work-orders/${id}`)
      record.value = res.data
      void loadAttachments(id)
    } catch (e) {
      record.value = null
      if (e instanceof ApiError) {
        if (e.status === 404) notFound.value = true
        else if (e.status === 403) forbidden.value = true
        else error.value = e.message
      } else {
        error.value = 'Failed to load work order.'
      }
    } finally {
      loading.value = false
    }
  }

  async function loadAttachments(id: number | string) {
    attachmentsLoading.value = true
    try {
      const res = await api.get<{ data: Attachment[] }>(`/work-orders/${id}/attachments`)
      attachments.value = res.data
    } catch {
      attachments.value = []
    } finally {
      attachmentsLoading.value = false
    }
  }

  return {
    record, loading, error, notFound, forbidden,
    attachments, attachmentsLoading,
    isOpen, isInProgress, isCompleted, isTerminal, isAssignedToMe,
    canEdit, canAssign, canStart, canComplete, canClose, canCancel, canSetAssetStatus,
    load,
  }
}
```

**Step 2: Verify type-check passes for the new file**

```bash
npx vue-tsc --noEmit --project tsconfig.app.json 2>&1 | grep -E "useWorkOrderDetail|error" | head -5
```

Expected: no errors.

**Step 3: Commit**

```bash
git add frontend/src/composables/useWorkOrderDetail.ts
git commit -m "feat(frontend): scaffold useWorkOrderDetail — load + permissions"
```

---

### Task 2: Composable — edit description and assign technician

**Files:**
- Modify: `frontend/src/composables/useWorkOrderDetail.ts` (append new state + functions; update return)
- Reference: `frontend/src/composables/useMaintenanceRequestDetail.ts:32-139` (edit pattern)

**Step 1: Add edit-description state and functions**

Insert after the permissions section, before the `load` function:

```typescript
  // ── Edit state ────────────────────────────────────────────────────────────
  const editing   = ref(false)
  const saving    = ref(false)
  const editError = ref<string | null>(null)
  const draft     = ref<{ description: string }>({ description: '' })
  const validationErrors = ref<Record<string, string[]> | null>(null)

  function startEdit() {
    if (!record.value) return
    draft.value = { description: record.value.description ?? '' }
    validationErrors.value = null
    editError.value = null
    editing.value = true
  }

  function cancelEdit() {
    editing.value = false
    validationErrors.value = null
    editError.value = null
  }

  async function saveEdit() {
    if (!record.value) return
    saving.value = true
    validationErrors.value = null
    editError.value = null
    try {
      const res = await api.patch<{ data: WorkOrder }>(
        `/work-orders/${record.value.id}`,
        { description: draft.value.description || null },
      )
      record.value = res.data
      editing.value = false
      toast.success('Changes saved.')
    } catch (e) {
      if (e instanceof ApiError) {
        if (e.validationErrors) validationErrors.value = e.validationErrors
        else if (e.status === 403) editError.value = 'You do not have permission to edit this work order.'
        else editError.value = e.message
      } else {
        editError.value = 'Failed to save changes.'
      }
    } finally {
      saving.value = false
    }
  }
```

**Step 2: Add assign-technician state and functions**

Insert after the edit section:

```typescript
  // ── Assign state ─────────────────────────────────────────────────────────
  const assignOpen      = ref(false)
  const assignLoading   = ref(false)
  const technicians     = ref<UserRef[]>([])
  const techniciansLoading = ref(false)
  const selectedTechId  = ref<number | null>(null)

  async function openAssign() {
    selectedTechId.value = null
    assignOpen.value = true
    if (technicians.value.length === 0 && !techniciansLoading.value) {
      techniciansLoading.value = true
      try {
        const res = await api.get<{ data: { id: number; name: string; role?: { code: string }; is_active: boolean }[] }>('/users')
        technicians.value = (res.data ?? [])
          .filter((u) => (u.role?.code === 'technician' || u.role?.code === 'Technician') && u.is_active)
          .map((u) => ({ id: u.id, name: u.name }))
      } catch {
        technicians.value = []
      } finally {
        techniciansLoading.value = false
      }
    }
  }

  async function doAssign() {
    if (!record.value || !selectedTechId.value) return
    assignLoading.value = true
    try {
      await api.post(`/work-orders/${record.value.id}/assign`, { user_id: selectedTechId.value })
      toast.success('Work order assigned.')
      assignOpen.value = false
      await load(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to assign work order.')
    } finally {
      assignLoading.value = false
    }
  }
```

**Step 3: Update the return statement**

Add `editing, saving, editError, draft, validationErrors, startEdit, cancelEdit, saveEdit, assignOpen, assignLoading, technicians, techniciansLoading, selectedTechId, openAssign, doAssign` to the returned object.

**Step 4: Type-check**

```bash
npx vue-tsc --noEmit --project tsconfig.app.json 2>&1 | grep -E "useWorkOrderDetail|error TS" | head -5
```

Expected: no errors.

**Step 5: Commit**

```bash
git add frontend/src/composables/useWorkOrderDetail.ts
git commit -m "feat(frontend): add edit description + assign to useWorkOrderDetail"
```

---

### Task 3: Composable — workflow transitions (start/complete/close/cancel)

**Files:**
- Modify: `frontend/src/composables/useWorkOrderDetail.ts` (append, update return)
- Reference: `frontend/src/composables/useMaintenanceRequestDetail.ts:141-201` (workflow actions pattern)

**Step 1: Add workflow transition state and functions**

Insert after the assign section:

```typescript
  // ── Workflow transitions ─────────────────────────────────────────────────
  const startLoading = ref(false)

  const completeOpen    = ref(false)
  const completeLoading = ref(false)
  const completionNotes = ref('')

  const closeLoading = ref(false)

  const cancelOpen    = ref(false)
  const cancelLoading = ref(false)
  const cancelReason  = ref('')

  async function doStart() {
    if (!record.value) return
    startLoading.value = true
    try {
      await api.post(`/work-orders/${record.value.id}/start`)
      toast.success('Work order started.')
      await load(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to start work order.')
    } finally {
      startLoading.value = false
    }
  }

  function openComplete() { completionNotes.value = ''; completeOpen.value = true }
  async function doComplete() {
    if (!record.value) return
    completeLoading.value = true
    try {
      await api.post(`/work-orders/${record.value.id}/complete`, {
        completion_notes: completionNotes.value.trim() || null,
      })
      toast.success('Work order marked completed.')
      completeOpen.value = false
      await load(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to complete work order.')
    } finally {
      completeLoading.value = false
    }
  }

  async function doClose() {
    if (!record.value) return
    closeLoading.value = true
    try {
      await api.post(`/work-orders/${record.value.id}/close`)
      toast.success('Work order closed.')
      await load(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to close work order.')
    } finally {
      closeLoading.value = false
    }
  }

  function openCancel() { cancelReason.value = ''; cancelOpen.value = true }
  async function doCancel() {
    if (!record.value || !cancelReason.value.trim()) return
    cancelLoading.value = true
    try {
      await api.post(`/work-orders/${record.value.id}/cancel`, { reason: cancelReason.value.trim() })
      toast.success('Work order cancelled.')
      cancelOpen.value = false
      await load(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to cancel work order.')
    } finally {
      cancelLoading.value = false
    }
  }
```

**Step 2: Update the return statement**

Add `startLoading, completeOpen, completeLoading, completionNotes, openComplete, doComplete, doStart, doClose, closeLoading, cancelOpen, cancelLoading, cancelReason, openCancel, doCancel`.

**Step 3: Type-check**

```bash
npx vue-tsc --noEmit --project tsconfig.app.json 2>&1 | grep -E "useWorkOrderDetail|error TS" | head -5
```

Expected: no errors.

**Step 4: Commit**

```bash
git add frontend/src/composables/useWorkOrderDetail.ts
git commit -m "feat(frontend): add workflow transitions to useWorkOrderDetail"
```

---

### Task 4: Composable — parts, readings, asset status, attachments, derived

**Files:**
- Modify: `frontend/src/composables/useWorkOrderDetail.ts` (append, update return)
- Reference: `frontend/src/components/app/AssetCombobox.vue` (searchable picker pattern — but the view does the rendering; composable just fetches data)

**Step 1: Add parts-management state and functions**

Insert after workflow transitions:

```typescript
  // ── Parts state ──────────────────────────────────────────────────────────
  const addPartOpen    = ref(false)
  const addPartLoading = ref(false)
  const partDraft = ref<{ partId: number | null; quantity: number; notes: string }>({
    partId: null, quantity: 1, notes: '',
  })
  const partsSearch   = ref('')
  const partsResults   = ref<{ id: number; name: string; erp_part_code: string; unit_of_measure: string | null }[]>([])
  const partsSearchLoading = ref(false)

  const removeTarget  = ref<number | null>(null)  // part-line id
  const removeLoading = ref(false)

  function openAddPart() {
    partDraft.value = { partId: null, quantity: 1, notes: '' }
    partsSearch.value = ''
    partsResults.value = []
    addPartOpen.value = true
  }

  async function searchParts(q: string) {
    partsSearch.value = q
    if (q.length < 2) { partsResults.value = []; return }
    partsSearchLoading.value = true
    try {
      const res = await api.get<{ data: { id: number; name: string; erp_part_code: string; unit_of_measure: string | null }[] }>('/parts', { search: q })
      partsResults.value = (res.data ?? []).slice(0, 10)
    } catch {
      partsResults.value = []
    } finally {
      partsSearchLoading.value = false
    }
  }

  async function doAddPart() {
    if (!record.value || !partDraft.value.partId) return
    addPartLoading.value = true
    try {
      await api.post(`/work-orders/${record.value.id}/parts`, {
        part_id: partDraft.value.partId,
        quantity: partDraft.value.quantity,
        notes: partDraft.value.notes || null,
      })
      toast.success('Part added.')
      addPartOpen.value = false
      await load(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to add part.')
    } finally {
      addPartLoading.value = false
    }
  }

  function openRemovePart(partLineId: number) { removeTarget.value = partLineId }
  async function doRemovePart() {
    if (!record.value || !removeTarget.value) return
    removeLoading.value = true
    try {
      await api.delete(`/work-orders/${record.value.id}/parts/${removeTarget.value}`)
      toast.success('Part removed.')
      removeTarget.value = null
      await load(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to remove part.')
    } finally {
      removeLoading.value = false
    }
  }
```

**Step 2: Add readings, asset status, and upload state/functions**

Insert after parts:

```typescript
  // ── Readings state ───────────────────────────────────────────────────────
  const readingTypes = ref<{ id: number; name: string; unit: string }[]>([])
  const recordReadingOpen  = ref(false)
  const readingLoading     = ref(false)
  const readingDraft = ref<{ typeId: number | null; value: number | null; readAt: string; notes: string }>({
    typeId: null, value: null, readAt: new Date().toISOString().slice(0, 10), notes: '',
  })

  const assetReadings = ref<{ id: number; usage_reading_type_id: number; reading_value: number; reading_at: string; confirmed_at: string | null }[]>([])
  const readingsLoading = ref(false)

  // Derived: "since last service" for PM-sourced WOs with reading-triggered rules
  const sinceLastService = computed(() => null as { type: string; since: number; interval: number; unit: string } | null)
  // (populated after load in Task 8 when MR + PM rule data is available)

  async function openRecordReading() {
    readingDraft.value = { typeId: null, value: null, readAt: new Date().toISOString().slice(0, 10), notes: '' }
    recordReadingOpen.value = true
    if (readingTypes.value.length === 0) {
      try {
        const res = await api.get<{ data: { id: number; name: string; unit: string }[] }>('/usage-reading-types')
        readingTypes.value = (res.data ?? []).filter((t) => t.name)
      } catch { /* silent */ }
    }
  }

  async function doRecordReading() {
    if (!record.value || !readingDraft.value.typeId || readingDraft.value.value == null) return
    readingLoading.value = true
    try {
      await api.post(`/assets/${record.value.asset.id}/meter-readings`, {
        usage_reading_type_id: readingDraft.value.typeId,
        reading_value: readingDraft.value.value,
        reading_at: readingDraft.value.readAt,
        source: 'manual',
        notes: readingDraft.value.notes || null,
      })
      toast.success('Meter reading recorded.')
      recordReadingOpen.value = false
      await loadAssetReadings()
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to record reading.')
    } finally {
      readingLoading.value = false
    }
  }

  async function loadAssetReadings() {
    if (!record.value) return
    readingsLoading.value = true
    try {
      const res = await api.get<{ data: { id: number; usage_reading_type_id: number; reading_value: number; reading_at: string; confirmed_at: string | null }[] }>(
        `/assets/${record.value.asset.id}/meter-readings`
      )
      assetReadings.value = res.data ?? []
    } catch {
      assetReadings.value = []
    } finally {
      readingsLoading.value = false
    }
  }

  // ── Asset status state ───────────────────────────────────────────────────
  const assetStatusOpen    = ref(false)
  const assetStatusLoading = ref(false)
  const selectedStatus     = ref<string | null>(null)

  function openSetAssetStatus() {
    selectedStatus.value = record.value?.asset?.operational_status ?? null
    assetStatusOpen.value = true
  }

  async function doSetAssetStatus() {
    if (!record.value || !selectedStatus.value) return
    assetStatusLoading.value = true
    try {
      await api.post(`/work-orders/${record.value.id}/asset-status`, {
        operational_status: selectedStatus.value,
      })
      toast.success('Asset status updated.')
      assetStatusOpen.value = false
      await load(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to update asset status.')
    } finally {
      assetStatusLoading.value = false
    }
  }

  // ── Upload state ─────────────────────────────────────────────────────────
  const uploadOpen    = ref(false)
  const uploadLoading = ref(false)
  const uploadFiles   = ref<File[]>([])

  function openUpload() { uploadFiles.value = []; uploadOpen.value = true }
  function addFiles(files: FileList | File[]) { uploadFiles.value.push(...Array.from(files)) }
  function removeFile(i: number) { uploadFiles.value.splice(i, 1) }

  async function doUpload() {
    if (!record.value || uploadFiles.value.length === 0) return
    uploadLoading.value = true
    try {
      for (const f of uploadFiles.value) {
        const form = new FormData()
        form.append('file', f)
        await api.upload(`/work-orders/${record.value.id}/attachments`, form)
      }
      toast.success(uploadFiles.value.length === 1 ? 'Attachment uploaded.' : `${uploadFiles.value.length} attachments uploaded.`)
      uploadOpen.value = false
      uploadFiles.value = []
      await loadAttachments(record.value.id)
      await load(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to upload attachments.')
    } finally {
      uploadLoading.value = false
    }
  }
```

**Step 3: Update the return statement**

Add all new exports: `addPartOpen, addPartLoading, partDraft, partsSearch, partsResults, partsSearchLoading, searchParts, openAddPart, doAddPart, removeTarget, removeLoading, openRemovePart, doRemovePart, readingTypes, recordReadingOpen, readingLoading, readingDraft, assetReadings, readingsLoading, sinceLastService, openRecordReading, doRecordReading, loadAssetReadings, assetStatusOpen, assetStatusLoading, selectedStatus, openSetAssetStatus, doSetAssetStatus, uploadOpen, uploadLoading, uploadFiles, openUpload, addFiles, removeFile, doUpload`.

**Step 4: Type-check**

```bash
npx vue-tsc --noEmit --project tsconfig.app.json 2>&1 | grep -E "useWorkOrderDetail|error TS" | head -5
```

Expected: no errors (the `operational_status` field may not exist on the `WorkOrder` type's `asset` sub-object — if so, add `operational_status?: string` to the `AssetRef` interface in `types/index.ts`).

**Step 5: Commit**

```bash
git add frontend/src/composables/useWorkOrderDetail.ts
git commit -m "feat(frontend): add parts, readings, asset status, upload to useWorkOrderDetail"
```

---

### Task 5: CSS — detail-table classes

**Files:**
- Modify: `frontend/src/style.css`

**Step 1: Add in-card table classes**

At the bottom of `frontend/src/style.css`, add:

```css
/* ── In-card detail table (compact, for parts-used etc.) ─────────────────── */

.detail-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--text-sm);
}

.detail-table-head {
  text-align: left;
  font-weight: var(--font-semibold);
  color: var(--muted-foreground);
  border-bottom: 1px solid var(--border);
}

.detail-table-head th {
  padding: var(--spacing-2) var(--spacing-3);
  white-space: nowrap;
}

.detail-table-row {
  border-bottom: 1px solid var(--border);
}

.detail-table-row:last-child {
  border-bottom: none;
}

.detail-table-cell {
  padding: var(--spacing-2) var(--spacing-3);
  vertical-align: middle;
}
```

**Step 2: Type-check to confirm no CSS-level issues (build catches broken imports, not CSS)**

```bash
npx vue-tsc --noEmit --project tsconfig.app.json 2>&1 | head -3
```

Expected: no new errors.

**Step 3: Commit**

```bash
git add frontend/src/style.css
git commit -m "style(frontend): add detail-table semantic classes for in-card tables"
```

---

### Task 6: View template — build the WorkOrderDetailView

**Files:**
- Modify: `frontend/src/views/work-orders/WorkOrderDetailView.vue`
- Reference: `frontend/src/views/work-orders/MaintenanceRequestDetailView.vue` (layout pattern)
- Reference: `frontend/src/views/work-orders/WorkOrdersView.vue:196-210` (FileInput upload pattern)
- Reference: `frontend/src/lib/displayHelpers.ts` (woStatusClass, woStatusLabel, priorityClass, priorityLabel, fmtDate, formatBytes)

This is the largest single task. The full template mirrors the MR detail page structure:

1. Back button (router.back())
2. Loading / notFound / forbidden / error states
3. Terminal banner for closed/cancelled
4. Details card (read-only grid: asset, source MR, timestamps, assignee)
5. Work notes card (read/edit toggle + Textarea)
6. Related MR card (RouterLink when maintenance_request exists)
7. Parts used card (detail-table or empty-state, Add Part button + Dialog, remove × with AlertDialog)
8. Updated readings card (asset's readings list, Record reading button + Dialog, since-last-service line)
9. Final asset status card (current status badge, Update status button + Select in Dialog)
10. Attachments card (view list, Upload button + inline file-list with FileInput)
11. Action bar (status-aware buttons: Assign/Start/Complete/Close/Cancel)

All Dialogs follow the MR page pattern (Dialog → DialogContent → DialogHeader/DialogFooter with Back/Confirm buttons labeled with the exact action).

**Step 1: Write the full view template**

Replace the contents of `frontend/src/views/work-orders/WorkOrderDetailView.vue`:

```vue
<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { ArrowLeftIcon } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog'
import { AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription, AlertDialogFooter, AlertDialogAction, AlertDialogCancel } from '@/components/ui/alert-dialog'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Input } from '@/components/ui/input'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { FileInput } from '@/components/ui/file-input'
import { useWorkOrderDetail } from '@/composables/useWorkOrderDetail'
import { woStatusClass, woStatusLabel, priorityClass, priorityLabel, fmtDate, formatBytes } from '@/lib/displayHelpers'

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
  load,
  // Edit
  editing, saving, editError, draft, validationErrors, startEdit, cancelEdit, saveEdit,
  // Assign
  assignOpen, assignLoading, technicians, techniciansLoading, selectedTechId, openAssign, doAssign,
  // Workflow
  startLoading, completeOpen, completeLoading, completionNotes, openComplete, doComplete, doStart, doClose, closeLoading, cancelOpen, cancelLoading, cancelReason, openCancel, doCancel,
  // Parts
  addPartOpen, addPartLoading, partDraft, partsSearch, partsResults, partsSearchLoading, searchParts, openAddPart, doAddPart, removeTarget, removeLoading, openRemovePart, doRemovePart,
  // Readings
  readingTypes, recordReadingOpen, readingLoading, readingDraft, assetReadings, readingsLoading, sinceLastService, openRecordReading, doRecordReading, loadAssetReadings,
  // Asset status
  assetStatusOpen, assetStatusLoading, selectedStatus, openSetAssetStatus, doSetAssetStatus,
  // Upload
  uploadOpen, uploadLoading, uploadFiles, fileInputRef, openUpload, addFiles, removeFile, doUpload,
} = useWorkOrderDetail()

watch(id, (newId) => {
  if (newId) {
    load(newId)
    loadAssetReadings()
  }
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
            <p class="page-subtitle">
              {{ record.maintenance_request?.type === 'preventive' ? 'Preventive' : 'Corrective' }} work order
            </p>
          </div>
          <div class="page-actions">
            <span :class="woStatusClass(record.status)">{{ woStatusLabel(record.status) }}</span>
            <span :class="priorityClass(record.priority)">{{ priorityLabel(record.priority) }}</span>
          </div>
        </div>

        <!-- Terminal banner -->
        <div v-if="isTerminal" class="detail-banner">
          This work order is {{ woStatusLabel(record.status).toLowerCase() }} and can no longer be changed.
        </div>

        <!-- Details card -->
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
              <div class="detail-field" v-if="record.assigned_to">
                <span class="detail-field-label">Assigned to</span>
                <p class="detail-field-value">{{ record.assigned_to.name }}</p>
              </div>
              <div class="detail-field" v-else-if="canAssign">
                <span class="detail-field-label">Assigned to</span>
                <p class="detail-field-value detail-field-muted">Unassigned</p>
              </div>
              <div class="detail-field" v-if="record.assigned_by">
                <span class="detail-field-label">Assigned by</span>
                <p class="detail-field-value">{{ record.assigned_by.name }}</p>
              </div>
              <div class="detail-field" v-if="record.started_at">
                <span class="detail-field-label">Started</span>
                <p class="detail-field-value">{{ fmtDate(record.started_at) }}</p>
              </div>
              <div class="detail-field" v-if="record.completed_at">
                <span class="detail-field-label">Completed</span>
                <p class="detail-field-value">{{ fmtDate(record.completed_at) }}</p>
              </div>
              <div class="detail-field" v-if="record.closed_at">
                <span class="detail-field-label">Closed</span>
                <p class="detail-field-value">{{ fmtDate(record.closed_at) }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Work notes card -->
        <div class="data-card">
          <div class="data-card-header"><h2 class="data-card-title">Work notes</h2></div>
          <div class="data-card-content">
            <div class="detail-field detail-field-block">
              <!-- Read mode: show description + completion notes -->
              <template v-if="!editing">
                <p class="detail-field-value detail-field-prose">
                  {{ record.description ?? 'No description provided.' }}
                </p>
                <div v-if="record.completion_notes" class="detail-section">
                  <h3 class="detail-section-title">Completion notes</h3>
                  <p class="detail-field-value detail-field-prose">{{ record.completion_notes }}</p>
                </div>
              </template>
              <!-- Edit mode -->
              <template v-else>
                <Label for="wo-description" class="detail-field-label">Description</Label>
                <Textarea id="wo-description" v-model="draft.description" :rows="5" placeholder="Describe the work performed…" />
                <p v-if="validationErrors?.description" class="form-error">{{ validationErrors.description[0] }}</p>
              </template>
            </div>
          </div>
        </div>

        <!-- Related MR card -->
        <div v-if="record.maintenance_request" class="data-card">
          <div class="data-card-header"><h2 class="data-card-title">Related maintenance request</h2></div>
          <div class="data-card-content">
            <RouterLink :to="`/mr/${record.maintenance_request.id}`" class="table-link">
              {{ record.maintenance_request.number }}
            </RouterLink>
          </div>
        </div>

        <!-- Parts used card -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Parts used</h2>
            <Button v-if="canEdit" size="sm" variant="outline" @click="openAddPart">Add Part</Button>
          </div>
          <div class="data-card-content">
            <div v-if="record.parts && record.parts.length > 0">
              <table class="detail-table">
                <thead>
                  <tr class="detail-table-head">
                    <th>Code</th>
                    <th>Name</th>
                    <th>Qty</th>
                    <th>UoM</th>
                    <th>Notes</th>
                    <th v-if="canEdit"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="p in record.parts" :key="p.id" class="detail-table-row">
                    <td class="detail-table-cell">{{ p.part.erp_part_code }}</td>
                    <td class="detail-table-cell">{{ p.part.name }}</td>
                    <td class="detail-table-cell">{{ p.quantity }}</td>
                    <td class="detail-table-cell">{{ p.part.unit_of_measure ?? '—' }}</td>
                    <td class="detail-table-cell">{{ p.notes ?? '—' }}</td>
                    <td class="detail-table-cell" v-if="canEdit">
                      <Button variant="ghost" size="icon" class="detail-table-remove" aria-label="Remove part" :disabled="removeLoading" @click="openRemovePart(p.id)">
                        &#10005;
                      </Button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="empty-state">No parts recorded.</div>
          </div>
        </div>

        <!-- Updated readings card -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Updated readings</h2>
            <Button v-if="canEdit" size="sm" variant="outline" @click="openRecordReading">Record reading</Button>
          </div>
          <div class="data-card-content">
            <div v-if="readingsLoading" class="loading-state">Loading readings…</div>
            <div v-else-if="assetReadings.length === 0" class="empty-state">No readings recorded.</div>
            <ul v-else class="reading-list">
              <li v-for="r in assetReadings" :key="r.id" class="reading-item">
                <span class="reading-type">{{ readingTypes.find(t => t.id === r.usage_reading_type_id)?.name ?? r.usage_reading_type_id }}</span>
                <span class="reading-value">{{ r.reading_value }}</span>
                <span class="reading-date">{{ fmtDate(r.reading_at) }}</span>
                <span :class="r.confirmed_at ? 'reading-confirmed' : 'reading-unverified'">
                  {{ r.confirmed_at ? 'Confirmed' : 'Unverified' }}
                </span>
              </li>
            </ul>
            <div v-if="sinceLastService" class="detail-section">
              <h3 class="detail-section-title">Since last service</h3>
              <p class="detail-field-value">{{ sinceLastService.since }} {{ sinceLastService.unit }} / {{ sinceLastService.interval }} {{ sinceLastService.unit }} interval</p>
            </div>
          </div>
        </div>

        <!-- Final asset status card -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Asset status</h2>
            <Button v-if="canSetAssetStatus" size="sm" variant="outline" @click="openSetAssetStatus">Update status</Button>
          </div>
          <div class="data-card-content">
            <p class="detail-field-value">
              <span :class="'status-badge'">{{ record.asset?.operational_status ?? 'Unknown' }}</span>
            </p>
          </div>
        </div>

        <!-- Attachments card -->
        <div class="data-card">
          <div class="data-card-header">
            <h2 class="data-card-title">Attachments</h2>
            <Button v-if="canEdit" size="sm" variant="outline" @click="openUpload">Upload</Button>
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

        <!-- Action bar -->
        <div v-if="!isTerminal && (canAssign || canStart || canComplete || canClose || canCancel)" class="detail-actions">
          <Button v-if="canCancel" variant="outline" @click="openCancel">Cancel</Button>
          <Button v-if="canAssign && !record.assigned_to" @click="openAssign">Assign…</Button>
          <Button v-if="canAssign && record.assigned_to" variant="outline" @click="openAssign">Reassign…</Button>
          <Button v-if="canStart" @click="doStart" :disabled="startLoading">
            {{ startLoading ? 'Starting…' : 'Start' }}
          </Button>
          <Button v-if="canComplete" @click="openComplete">Complete…</Button>
          <Button v-if="canClose" @click="doClose" :disabled="closeLoading">
            {{ closeLoading ? 'Closing…' : 'Close' }}
          </Button>
        </div>
      </template>

    </div>

    <!-- Assign dialog -->
    <Dialog v-model:open="assignOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{{ record?.assigned_to ? 'Reassign' : 'Assign' }} work order {{ record?.number }}</DialogTitle>
          <DialogDescription>Select a Technician to assign this work order to.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="tech-select">Technician</Label>
          <Select v-model="selectedTechId">
            <SelectTrigger id="tech-select"><SelectValue placeholder="Select a Technician…" /></SelectTrigger>
            <SelectContent>
              <div v-if="techniciansLoading" class="loading-state">Loading…</div>
              <SelectItem v-for="t in technicians" :key="t.id" :value="t.id">{{ t.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="assignLoading" @click="assignOpen = false">Back</Button>
          <Button :disabled="assignLoading || !selectedTechId" @click="doAssign">
            {{ assignLoading ? 'Assigning…' : 'Assign' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Complete dialog -->
    <Dialog v-model:open="completeOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Complete work order {{ record?.number }}?</DialogTitle>
          <DialogDescription>Provide completion notes (optional). This cannot be undone.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="completion-notes">Completion notes</Label>
          <Textarea id="completion-notes" v-model="completionNotes" :rows="4" placeholder="Describe what was done…" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="completeLoading" @click="completeOpen = false">Back</Button>
          <Button :disabled="completeLoading" @click="doComplete">
            {{ completeLoading ? 'Completing…' : 'Complete Work Order' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Cancel confirmation -->
    <Dialog v-model:open="cancelOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Cancel work order {{ record?.number }}?</DialogTitle>
          <DialogDescription>A reason is required. This cannot be undone.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="cancel-reason">Reason</Label>
          <Textarea id="cancel-reason" v-model="cancelReason" :rows="4" placeholder="Explain why this work order is cancelled…" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="cancelLoading" @click="cancelOpen = false">Back</Button>
          <Button :disabled="cancelLoading || !cancelReason.trim()" @click="doCancel">
            {{ cancelLoading ? 'Cancelling…' : 'Cancel Work Order' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Add Part dialog -->
    <Dialog v-model:open="addPartOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add part to {{ record?.number }}</DialogTitle>
          <DialogDescription>Search for a part to add.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="part-search">Part</Label>
          <Input id="part-search" v-model="partsSearch" placeholder="Search parts…" @input="searchParts(($event.target as HTMLInputElement).value)" />
          <ul v-if="partsResults.length > 0" class="search-results">
            <li v-for="p in partsResults" :key="p.id" class="search-result-item" :class="{ selected: partDraft.partId === p.id }" @click="partDraft.partId = p.id">
              {{ p.erp_part_code }} — {{ p.name }}
            </li>
          </ul>
          <p v-if="!partsSearchLoading && partsSearch.length >= 2 && partsResults.length === 0" class="detail-field-muted">No parts found.</p>
        </div>
        <div class="form-field">
          <Label for="part-qty">Quantity</Label>
          <Input id="part-qty" v-model.number="partDraft.quantity" type="number" min="0.01" step="any" />
        </div>
        <div class="form-field">
          <Label for="part-notes">Notes <span class="field-optional">— optional</span></Label>
          <Textarea id="part-notes" v-model="partDraft.notes" :rows="2" placeholder="e.g., batch number…" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="addPartLoading" @click="addPartOpen = false">Back</Button>
          <Button :disabled="addPartLoading || !partDraft.partId || partDraft.quantity <= 0" @click="doAddPart">
            {{ addPartLoading ? 'Adding…' : 'Add Part' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Remove part confirmation -->
    <AlertDialog :open="removeTarget !== null" @update:open="(v: boolean) => { if (!v) removeTarget = null }">
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Remove this part?</AlertDialogTitle>
          <AlertDialogDescription>This will remove the part line from the work order. This can be re-added later.</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel :disabled="removeLoading" @click="removeTarget = null">Cancel</AlertDialogCancel>
          <AlertDialogAction :disabled="removeLoading" @click="doRemovePart">
            {{ removeLoading ? 'Removing…' : 'Remove' }}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>

    <!-- Record reading dialog -->
    <Dialog v-model:open="recordReadingOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Record meter reading</DialogTitle>
          <DialogDescription>Record a new reading for {{ record?.asset.name }}.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="reading-type">Reading type</Label>
          <Select v-model="readingDraft.typeId">
            <SelectTrigger id="reading-type"><SelectValue placeholder="Select type…" /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="rt in readingTypes" :key="rt.id" :value="rt.id">{{ rt.name }} ({{ rt.unit }})</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="form-field">
          <Label for="reading-value">Value</Label>
          <Input id="reading-value" v-model.number="readingDraft.value" type="number" step="any" />
        </div>
        <div class="form-field">
          <Label for="reading-date">Date</Label>
          <Input id="reading-date" v-model="readingDraft.readAt" type="date" />
        </div>
        <div class="form-field">
          <Label for="reading-notes">Notes <span class="field-optional">— optional</span></Label>
          <Textarea id="reading-notes" v-model="readingDraft.notes" :rows="2" />
        </div>
        <DialogFooter>
          <Button variant="outline" :disabled="readingLoading" @click="recordReadingOpen = false">Back</Button>
          <Button :disabled="readingLoading || !readingDraft.typeId || readingDraft.value == null" @click="doRecordReading">
            {{ readingLoading ? 'Recording…' : 'Record Reading' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Asset status dialog -->
    <Dialog v-model:open="assetStatusOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Update asset status</DialogTitle>
          <DialogDescription>Set the operational status for {{ record?.asset.name }}.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Label for="asset-status">Status</Label>
          <Select v-model="selectedStatus">
            <SelectTrigger id="asset-status"><SelectValue /></SelectTrigger>
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
          <Button :disabled="assetStatusLoading || !selectedStatus" @click="doSetAssetStatus">
            {{ assetStatusLoading ? 'Updating…' : 'Update Status' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Upload dialog -->
    <Dialog v-model:open="uploadOpen">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Upload attachments</DialogTitle>
          <DialogDescription>Add files to {{ record?.number }}. PDF, images, Office — max 20 MB each.</DialogDescription>
        </DialogHeader>
        <div class="form-field">
          <Button type="button" variant="outline" class="file-pick-btn" @click="fileInputRef?.open()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/></svg>
            Attach files
          </Button>
          <FileInput ref="fileInputRef" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx" @change="addFiles" />
          <ul v-if="uploadFiles.length > 0" class="file-list">
            <li v-for="(f, i) in uploadFiles" :key="i" class="file-list-item">
              <span class="file-list-name">{{ f.name }}</span>
              <span class="file-list-size">{{ formatBytes(f.size) }}</span>
              <Button type="button" variant="ghost" size="icon" class="file-list-remove" aria-label="Remove attachment" @click="removeFile(i)">&#10005;</Button>
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
```

**Step 2: Type-check**

```bash
npx vue-tsc --noEmit --project tsconfig.app.json 2>&1
```

Expected: initial errors are expected (missing imports, `fileInputRef` not exposed from composable, `AlertDialog` import path may need verification, `operational_status` on asset may need a type tweak). Resolve errors by:
- Adding `fileInputRef` to the composable return (it's a template ref for the FileInput component — add `const fileInputRef = ref<InstanceType<typeof FileInput> | null>(null)` to the composable, exported in return).
- Verifying `AlertDialog` export path exists (check `src/components/ui/alert-dialog/` directory).
- If `operational_status` is not on the `AssetRef` type, add `operational_status?: string` to the `AssetRef` interface in `types/index.ts`.

**Step 3: Fix remaining type errors and retry until clean**

```bash
npx vue-tsc --noEmit --project tsconfig.app.json 2>&1
```

Expected: no errors.

**Step 4: Manual smoke check**

Start Vite dev server (`npm run dev`), navigate to `http://localhost:5173/work-orders/:id` with a valid WO id. Verify:
- Back button returns to previous page.
- Details card shows WO info.
- Status/priority badges render with correct colors.
- Action bar shows appropriate buttons for the current status.
- Terminating banners and empty states render correctly.

**Step 5: Commit**

```bash
git add frontend/src/views/work-orders/WorkOrderDetailView.vue frontend/src/composables/useWorkOrderDetail.ts frontend/src/types/index.ts
git commit -m "feat(frontend): build Work Order detail page — full lifecycle"
```

---

### Task 7: Final verification — type-check + build

**Files:**
- All frontend files.

**Step 1: Full type-check**

```bash
npm run type-check
```

Expected: `vue-tsc --build` exits with 0.

**Step 2: Production build**

```bash
npm run build
```

Expected: `vite build` exits with 0, no warnings for unused imports or TypeScript errors.

**Step 3: Commit (if any final tweaks)**

```bash
git status
git add -A frontend/
git commit -m "chore(frontend): final type-check + build verification for WO detail"
```
