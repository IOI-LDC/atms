import { ref, computed } from 'vue'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'
import type { WorkOrder, Assignee, Attachment } from '@/types'

/**
 * Owns the state and actions for a single Work Order detail page.
 *
 * Backend contract (see docs/04-technical/BACKEND_API_REFERENCE.md):
 *  GET    /work-orders/{id}                  -> { data: WorkOrder }
 *  PATCH  /work-orders/{id}                  -> { data: WorkOrder }   (description only)
 *  POST   /work-orders/{id}/assign           -> { message, data }     { user_id }
 *  POST   /work-orders/{id}/start            -> { message, data }
 *  POST   /work-orders/{id}/complete         -> { message, data }     { completion_notes? }
 *  POST   /work-orders/{id}/close            -> { message, data }
 *  POST   /work-orders/{id}/cancel           -> { message, data }     { reason }
 *  POST   /work-orders/{id}/parts            -> { message, data }     { part_id, quantity, notes? }
 *  DELETE /work-orders/{id}/parts/{partLine} -> { message }
 *  POST   /work-orders/{id}/asset-status     -> { data: asset }       { operational_status }  [NEW — backend prerequisite]
 *  GET    /work-orders/{id}/attachments      -> { data: Attachment[] }
 *  POST   /work-orders/{id}/attachments      -> attachment            (multipart upload)
 *  GET    /admin/users                       -> { data: User[] }      (assignee picker; Admin/Manager)
 *  GET    /usage-reading-types               -> { data: Type[] }
 *  GET    /parts?search=                     -> cursor page           (parts picker)
 *  GET    /assets/{id}/meter-readings        -> { data: Reading[] }
 *  POST   /assets/{id}/meter-readings        -> { message, data }     (record reading)
 */
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
  // Assign while open; reassign while in progress too (the backend permits
  // assigning any non-closed/cancelled WO). Closed/cancelled stay locked.
  const canAssign = computed(() => !!record.value && (isOpen.value || isInProgress.value) && auth.isAdminOrManager)
  const canStart  = computed(() => !!record.value && isOpen.value && !!record.value.assigned_to && (auth.isAdminOrManager || isAssignedToMe.value))
  const canComplete = computed(() => !!record.value && isInProgress.value && (auth.isAdminOrManager || isAssignedToMe.value))
  const canClose  = computed(() => !!record.value && isCompleted.value && auth.isAdminOrManager)
  const canCancel = computed(() => !!record.value && !isTerminal.value && auth.isAdminOrManager)
  const canSetAssetStatus = computed(() => !!record.value && !isTerminal.value && (auth.isAdminOrManager || isAssignedToMe.value))

  // ── Edit state ────────────────────────────────────────────────────────────
  const editing   = ref(false)
  const saving    = ref(false)
  const editError = ref<string | null>(null)
  const draft     = ref<{ description: string }>({ description: '' })
  const validationErrors = ref<Record<string, string[]> | null>(null)

  // ── Assign state ─────────────────────────────────────────────────────────
  const assignOpen      = ref(false)
  const assignLoading   = ref(false)
  const technicians     = ref<Assignee[]>([])
  const techniciansLoading = ref(false)
  const selectedTechId  = ref<number | null>(null)

  // ── Workflow transitions ─────────────────────────────────────────────────
  const startLoading = ref(false)
  const completeOpen    = ref(false)
  const completeLoading = ref(false)
  const completionNotes = ref('')
  const closeLoading = ref(false)
  const cancelOpen    = ref(false)
  const cancelLoading = ref(false)
  const cancelReason  = ref('')

  // ── Parts state ──────────────────────────────────────────────────────────
  const addPartOpen    = ref(false)
  const addPartLoading = ref(false)
  const partDraft = ref<{ partId: number | null; quantity: number; notes: string }>({
    partId: null, quantity: 1, notes: '',
  })
  const partsSearch   = ref('')
  const partsResults   = ref<{ id: number; name: string; erp_part_code: string; unit_of_measure: string | null }[]>([])
  const partsSearchLoading = ref(false)
  const removeTarget  = ref<number | null>(null)
  const removeLoading = ref(false)

  // ── Readings state ───────────────────────────────────────────────────────
  const readingTypes = ref<{ id: number; name: string; unit: string }[]>([])
  const recordReadingOpen  = ref(false)
  const readingLoading     = ref(false)
  const readingDraft = ref<{ typeId: number | null; value: number | null; readAt: string; notes: string }>({
    typeId: null, value: null, readAt: new Date().toISOString().slice(0, 10), notes: '',
  })
  const assetReadings = ref<{ id: number; usage_reading_type_id: number; reading_value: number; reading_at: string; confirmed_at: string | null }[]>([])
  const readingsLoading = ref(false)

  // Derived: "since last service" for PM-sourced WOs. Null until PM rule data
  // is available; the view populates this when the WO's source MR carries a
  // reading-triggered PM rule. Left as a placeholder for the initial build.
  const sinceLastService = computed<{ type: string; since: number; interval: number; unit: string } | null>(() => null)

  // ── Asset status state ───────────────────────────────────────────────────
  const assetStatusOpen    = ref(false)
  const assetStatusLoading = ref(false)
  const selectedStatus     = ref<string | null>(null)

  // ── Upload state ─────────────────────────────────────────────────────────
  const uploadOpen    = ref(false)
  const uploadLoading = ref(false)
  const uploadFiles   = ref<File[]>([])

  // ══════════════════════════════════════════════════════════════════════════
  //  Load
  // ══════════════════════════════════════════════════════════════════════════
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

  // ══════════════════════════════════════════════════════════════════════════
  //  Edit description
  // ══════════════════════════════════════════════════════════════════════════
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

  // ══════════════════════════════════════════════════════════════════════════
  //  Assign technician
  // ══════════════════════════════════════════════════════════════════════════
  async function openAssign() {
    selectedTechId.value = null
    assignOpen.value = true
    if (technicians.value.length === 0 && !techniciansLoading.value) {
      techniciansLoading.value = true
      try {
        const res = await api.get<{ data: { id: number; name: string; role?: { code: string }; is_active: boolean }[] }>('/admin/users')
        technicians.value = (res.data ?? [])
          .filter((u) => u.is_active && (u.role?.code === 'technician' || u.role?.code === 'maintenance_manager'))
          .map((u) => ({ id: u.id, name: u.name, role: u.role?.code ?? '' }))
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

  // ══════════════════════════════════════════════════════════════════════════
  //  Workflow transitions
  // ══════════════════════════════════════════════════════════════════════════
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

  // ══════════════════════════════════════════════════════════════════════════
  //  Parts
  // ══════════════════════════════════════════════════════════════════════════
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

  // ══════════════════════════════════════════════════════════════════════════
  //  Readings
  // ══════════════════════════════════════════════════════════════════════════
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

  // ══════════════════════════════════════════════════════════════════════════
  //  Asset status
  // ══════════════════════════════════════════════════════════════════════════
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

  // ══════════════════════════════════════════════════════════════════════════
  //  Upload
  // ══════════════════════════════════════════════════════════════════════════
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

  return {
    // Load
    record, loading, error, notFound, forbidden,
    attachments, attachmentsLoading,
    // Derived + permissions
    isOpen, isInProgress, isCompleted, isTerminal, isAssignedToMe,
    canEdit, canAssign, canStart, canComplete, canClose, canCancel, canSetAssetStatus,
    // Edit
    editing, saving, editError, draft, validationErrors, startEdit, cancelEdit, saveEdit,
    // Assign
    assignOpen, assignLoading, technicians, techniciansLoading, selectedTechId, openAssign, doAssign,
    // Workflow
    startLoading, doStart,
    completeOpen, completeLoading, completionNotes, openComplete, doComplete,
    closeLoading, doClose,
    cancelOpen, cancelLoading, cancelReason, openCancel, doCancel,
    // Parts
    addPartOpen, addPartLoading, partDraft, partsSearch, partsResults, partsSearchLoading, searchParts, openAddPart, doAddPart, removeTarget, removeLoading, openRemovePart, doRemovePart,
    // Readings
    readingTypes, recordReadingOpen, readingLoading, readingDraft, assetReadings, readingsLoading, sinceLastService, openRecordReading, doRecordReading, loadAssetReadings,
    // Asset status
    assetStatusOpen, assetStatusLoading, selectedStatus, openSetAssetStatus, doSetAssetStatus,
    // Upload
    uploadOpen, uploadLoading, uploadFiles, openUpload, addFiles, removeFile, doUpload,
    // Init
    load, loadAttachments,
  }
}
