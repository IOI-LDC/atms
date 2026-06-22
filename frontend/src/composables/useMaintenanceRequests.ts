import { ref, computed } from 'vue'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'
import { useAssetSearch } from './useAssetSearch'
import type { MaintenanceRequest, CursorPage } from '@/types'

function mkList<T>() {
  return {
    items:       ref<T[]>([]),
    loading:     ref(false),
    loadingMore: ref(false),
    error:       ref<string | null>(null),
    nextCursor:  ref<string | null>(null),
    fetched:     false,
  }
}

export function useMaintenanceRequests() {
  const auth = useAuthStore()

  // ── All Requests (Admin/Manager) ──────────────────────────────────────────────

  const allMr = mkList<MaintenanceRequest>()

  async function loadAllRequests(append = false, force = false) {
    if (!append && !force && allMr.fetched) return
    if (!append) { allMr.items.value = []; allMr.nextCursor.value = null }
    const busy = append ? allMr.loadingMore : allMr.loading
    busy.value = true; allMr.error.value = null
    try {
      const p: Record<string, unknown> = { sort: 'created_at:desc', per_page: 25 }
      if (append && allMr.nextCursor.value) p.cursor = allMr.nextCursor.value
      const res = await api.get<CursorPage<MaintenanceRequest>>('/maintenance-requests', p)
      allMr.items.value = append ? [...allMr.items.value, ...res.data] : res.data
      allMr.nextCursor.value = res.meta.next_cursor
      allMr.fetched = true
    } catch { allMr.error.value = 'Failed to load all requests.' }
    finally { busy.value = false }
  }

  // ── Awaiting Review (Pending Approval) ────────────────────────────────────────

  const ar = mkList<MaintenanceRequest>()

  async function loadAwaiting(append = false, force = false) {
    if (!append && !force && ar.fetched) return
    if (!append) { ar.items.value = []; ar.nextCursor.value = null }
    const busy = append ? ar.loadingMore : ar.loading
    busy.value = true; ar.error.value = null
    try {
      const p: Record<string, unknown> = { status: 'pending_review', sort: 'created_at:asc', per_page: 25 }
      if (append && ar.nextCursor.value) p.cursor = ar.nextCursor.value
      const res = await api.get<CursorPage<MaintenanceRequest>>('/maintenance-requests', p)
      ar.items.value = append ? [...ar.items.value, ...res.data] : res.data
      ar.nextCursor.value = res.meta.next_cursor
      ar.fetched = true
    } catch { ar.error.value = 'Failed to load requests.' }
    finally { busy.value = false }
  }

  // ── My Requests ───────────────────────────────────────────────────────────────

  const mr = mkList<MaintenanceRequest>()

  async function loadMyRequests(append = false, force = false) {
    if (!append && !force && mr.fetched) return
    if (!append) { mr.items.value = []; mr.nextCursor.value = null }
    const busy = append ? mr.loadingMore : mr.loading
    busy.value = true; mr.error.value = null
    try {
      const p: Record<string, unknown> = { created_by: auth.user?.id, sort: 'created_at:desc', per_page: 25 }
      if (append && mr.nextCursor.value) p.cursor = mr.nextCursor.value
      const res = await api.get<CursorPage<MaintenanceRequest>>('/maintenance-requests', p)
      mr.items.value = append ? [...mr.items.value, ...res.data] : res.data
      mr.nextCursor.value = res.meta.next_cursor
      mr.fetched = true
    } catch { mr.error.value = 'Failed to load your requests.' }
    finally { busy.value = false }
  }

  // ── Approve ───────────────────────────────────────────────────────────────────

  const approveTarget  = ref<MaintenanceRequest | null>(null)
  const approveOpen    = ref(false)
  const approveLoading = ref(false)

  function openApprove(item: MaintenanceRequest) {
    approveTarget.value = item
    approveOpen.value   = true
  }

  async function doApprove() {
    if (!approveTarget.value) return
    approveLoading.value = true
    try {
      await api.post(`/maintenance-requests/${approveTarget.value.id}/approve`)
      toast.success('Request approved — work order created.')
      approveOpen.value = false
      loadAwaiting(false, true)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to approve request.')
    } finally { approveLoading.value = false }
  }

  // ── Reject ────────────────────────────────────────────────────────────────────

  const rejectTarget  = ref<MaintenanceRequest | null>(null)
  const rejectOpen    = ref(false)
  const rejectLoading = ref(false)
  const rejectReason  = ref('')

  function openReject(item: MaintenanceRequest) {
    rejectTarget.value = item
    rejectReason.value = ''
    rejectOpen.value   = true
  }

  async function doReject() {
    if (!rejectTarget.value || !rejectReason.value.trim()) return
    rejectLoading.value = true
    try {
      await api.post(`/maintenance-requests/${rejectTarget.value.id}/reject`, { reason: rejectReason.value.trim() })
      toast.success('Request rejected.')
      rejectOpen.value = false
      loadAwaiting(false, true)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to reject request.')
    } finally { rejectLoading.value = false }
  }

  // ── Cancel ────────────────────────────────────────────────────────────────────

  const cancelTarget  = ref<MaintenanceRequest | null>(null)
  const cancelOpen    = ref(false)
  const cancelLoading = ref(false)
  const cancelReason  = ref('')

  function openCancel(item: MaintenanceRequest) {
    cancelTarget.value = item
    cancelReason.value = ''
    cancelOpen.value   = true
  }

  async function doCancel() {
    if (!cancelTarget.value || !cancelReason.value.trim()) return
    cancelLoading.value = true
    try {
      await api.post(`/maintenance-requests/${cancelTarget.value.id}/cancel`, { reason: cancelReason.value.trim() })
      toast.success('Request cancelled.')
      cancelOpen.value = false
      loadMyRequests(false, true)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to cancel request.')
    } finally { cancelLoading.value = false }
  }

  // ── Create ────────────────────────────────────────────────────────────────────

  const assetSearch       = useAssetSearch()
  const createOpen        = ref(false)
  const confirmCreateOpen = ref(false)
  const createLoading     = ref(false)
  const createPriority    = ref('medium')
  const createDescription = ref('')
  const attachFiles       = ref<File[]>([])

  const canCreate = computed(() => !!auth.user)

  function requestCreate() {
    if (!assetSearch.selected.value) { toast.error('Please select an asset.'); return }
    confirmCreateOpen.value = true
  }

  function addFiles(files: File[]) {
    attachFiles.value.push(...files)
  }

  function removeFile(i: number) {
    attachFiles.value.splice(i, 1)
  }

  async function doCreate() {
    if (!assetSearch.selected.value) return
    createLoading.value = true
    try {
      const res = await api.post<{ data: MaintenanceRequest }>('/maintenance-requests/corrective', {
        asset_id:    assetSearch.selected.value.id,
        priority:    createPriority.value,
        description: createDescription.value || null,
      })
      const mrId = res.data.id
      for (const file of attachFiles.value) {
        const form = new FormData()
        form.append('file', file)
        await api.upload(`/maintenance-requests/${mrId}/attachments`, form)
      }
      toast.success('Maintenance request submitted.')
      confirmCreateOpen.value = false
      closeCreate()
      loadMyRequests(false, true)
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) {
        const first = Object.values(e.validationErrors)[0]?.[0]
        toast.error(first ?? 'Validation failed.')
      } else {
        toast.error(e instanceof ApiError ? e.message : 'Failed to submit request.')
      }
    } finally { createLoading.value = false }
  }

  function closeCreate() {
    createOpen.value        = false
    confirmCreateOpen.value = false
    createPriority.value    = 'medium'
    createDescription.value = ''
    attachFiles.value       = []
    assetSearch.reset()
  }

  return {
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
  }
}
