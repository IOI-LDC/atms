import { ref, computed } from 'vue'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'
import type { MaintenanceRequest, Attachment, Priority, Assignee } from '@/types'

/**
 * Owns the state and actions for a single Maintenance Request detail page.
 *
 * NOTE (assumptions, refine once the backend update/permission docs land):
 *  - PATCH /maintenance-requests/{id} accepts { description, priority }.
 *  - Edit is offered when the request is `pending_review` AND the user is the
 *    admin/manager or the original requester. The backend `update` gate stays
 *    authoritative.
 *  - Approve/Reject are offered to Admin/Manager; Cancel to Admin/Manager or the
 *    requester. Backend gates remain authoritative.
 *  - Role-gated fields (rejection/cancellation reason, preventive trigger info)
 *    are rendered by presence in the payload — the backend already strips them
 *    per role, so no client role checks are needed for display.
 */
export function useMaintenanceRequestDetail() {
  const auth = useAuthStore()

  // ── Record + load state ────────────────────────────────────────────────────
  const record    = ref<MaintenanceRequest | null>(null)
  const loading   = ref(false)
  const error     = ref<string | null>(null)
  const notFound  = ref(false)
  const forbidden = ref(false)

  // ── Edit state ──────────────────────────────────────────────────────────────
  const editing   = ref(false)
  const saving    = ref(false)
  const editError = ref<string | null>(null)
  const draft     = ref<{ description: string; priority: Priority }>({ description: '', priority: 'medium' })
  const validationErrors = ref<Record<string, string[]> | null>(null)

  // ── Attachments ─────────────────────────────────────────────────────────────
  const attachments       = ref<Attachment[]>([])
  const attachmentsLoading = ref(false)
  // Backend policy currently authorises attachment deletion for Admin/Manager
  // only (any attachment, any status). Owner-deletes-while-pending is a pending
  // backend enhancement — keep this gate aligned with what the API allows.
  const deleteAttachmentTarget  = ref<Attachment | null>(null)
  const deleteAttachmentLoading = ref(false)
  // Per-attachment, policy-driven. Prefer the backend `can_delete` flag; fall
  // back to Admin/Manager until that flag ships so behaviour is unchanged.
  function canDeleteAttachment(attachment: Attachment): boolean {
    return attachment.can_delete ?? auth.isAdminOrManager
  }

  // ── Workflow-action state ───────────────────────────────────────────────────
  const approveOpen    = ref(false)
  const approveLoading = ref(false)
  // Optional technician assignment performed at approval time (WO-02): the WO is
  // created by /approve, then assigned in a follow-up call. null = leave the new
  // work order unassigned.
  const approveTechnicians        = ref<Assignee[]>([])
  const approveTechniciansLoading = ref(false)
  const selectedApproveTechId     = ref<number | null>(null)
  const rejectOpen     = ref(false)
  const rejectLoading  = ref(false)
  const rejectReason   = ref('')
  const cancelOpen     = ref(false)
  const cancelLoading  = ref(false)
  const cancelReason   = ref('')

  // ── Derived ─────────────────────────────────────────────────────────────────
  const isPending  = computed(() => record.value?.status === 'pending_review')
  const isTerminal = computed(() => !!record.value && !isPending.value)
  const isOwnRequest = computed(() => !!record.value?.created_by && record.value.created_by.id === auth.user?.id)

  const canEdit    = computed(() => !!record.value && isPending.value && (auth.isAdminOrManager || isOwnRequest.value))
  const canApprove = computed(() => !!record.value && isPending.value && auth.isAdminOrManager)
  const canReject  = computed(() => !!record.value && isPending.value && auth.isAdminOrManager)
  const canCancel  = computed(() => !!record.value && isPending.value && (auth.isAdminOrManager || isOwnRequest.value))

  // ── Load ────────────────────────────────────────────────────────────────────
  async function load(id: number | string) {
    loading.value = true
    error.value = null
    notFound.value = false
    forbidden.value = false
    editing.value = false
    try {
      const res = await api.get<{ data: MaintenanceRequest }>(`/maintenance-requests/${id}`)
      record.value = res.data
      void loadAttachments(id)
    } catch (e) {
      record.value = null
      if (e instanceof ApiError) {
        if (e.status === 404) notFound.value = true
        else if (e.status === 403) forbidden.value = true
        else error.value = e.message
      } else {
        error.value = 'Failed to load maintenance request.'
      }
    } finally {
      loading.value = false
    }
  }

  async function loadAttachments(id: number | string) {
    attachmentsLoading.value = true
    try {
      // Both `{ data: Attachment[] }` and a CursorPage expose `.data` as the array.
      const res = await api.get<{ data: Attachment[] }>(`/maintenance-requests/${id}/attachments`)
      attachments.value = res.data
    } catch {
      attachments.value = []
    } finally {
      attachmentsLoading.value = false
    }
  }

  function openDeleteAttachment(attachment: Attachment) {
    deleteAttachmentTarget.value = attachment
  }

  function closeDeleteAttachment() {
    deleteAttachmentTarget.value = null
  }

  async function doDeleteAttachment() {
    const target = deleteAttachmentTarget.value
    if (!target || !record.value) {
      return
    }
    deleteAttachmentLoading.value = true
    try {
      await api.delete(`/attachments/${target.id}`)
      toast.success('Attachment deleted.')
      deleteAttachmentTarget.value = null
      await loadAttachments(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to delete attachment.')
    } finally {
      deleteAttachmentLoading.value = false
    }
  }

  // ── Edit ────────────────────────────────────────────────────────────────────
  function startEdit() {
    if (!record.value) return
    draft.value = { description: record.value.description ?? '', priority: record.value.priority }
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
      const res = await api.patch<{ data: MaintenanceRequest }>(
        `/maintenance-requests/${record.value.id}`,
        { description: draft.value.description || null, priority: draft.value.priority },
      )
      record.value = res.data
      editing.value = false
      toast.success('Changes saved.')
    } catch (e) {
      if (e instanceof ApiError) {
        if (e.validationErrors) validationErrors.value = e.validationErrors
        else if (e.status === 403) editError.value = 'You do not have permission to edit this request.'
        else editError.value = e.message
      } else {
        editError.value = 'Failed to save changes.'
      }
    } finally {
      saving.value = false
    }
  }

  // ── Workflow actions (refresh the record on success) ────────────────────────
  function openApprove() {
    selectedApproveTechId.value = null
    approveOpen.value = true
    void loadApproveTechnicians()
  }
  function openReject() { rejectReason.value = ''; rejectOpen.value = true }
  function openCancel() { cancelReason.value = ''; cancelOpen.value = true }

  async function loadApproveTechnicians() {
    if (approveTechnicians.value.length > 0 || approveTechniciansLoading.value) return
    approveTechniciansLoading.value = true
    try {
      const res = await api.get<{ data: { id: number; name: string; role?: { code: string }; is_active: boolean }[] }>('/admin/users')
      approveTechnicians.value = (res.data ?? [])
        .filter((u) => u.is_active && (u.role?.code === 'technician' || u.role?.code === 'maintenance_manager'))
        .map((u) => ({ id: u.id, name: u.name, role: u.role?.code ?? '' }))
    } catch {
      approveTechnicians.value = []
    } finally {
      approveTechniciansLoading.value = false
    }
  }

  async function doApprove() {
    if (!record.value) return
    approveLoading.value = true
    const assigneeId = selectedApproveTechId.value
    try {
      // Atomic approve + optional assign: the backend creates the WO and assigns
      // it inside one transaction, rolling back the MR conversion if the assignee
      // is ineligible. Omitting assignee_id keeps the WO unassigned.
      await api.post(
        `/maintenance-requests/${record.value.id}/approve`,
        assigneeId ? { assignee_id: assigneeId } : undefined,
      )
      approveOpen.value = false
      await load(record.value.id)
      toast.success(assigneeId
        ? 'Request approved — work order created and assigned.'
        : 'Request approved — work order created.')
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to approve request.')
    } finally {
      approveLoading.value = false
    }
  }

  async function doReject() {
    if (!record.value || !rejectReason.value.trim()) return
    rejectLoading.value = true
    try {
      await api.post(`/maintenance-requests/${record.value.id}/reject`, { reason: rejectReason.value.trim() })
      toast.success('Request rejected.')
      rejectOpen.value = false
      await load(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to reject request.')
    } finally {
      rejectLoading.value = false
    }
  }

  async function doCancel() {
    if (!record.value || !cancelReason.value.trim()) return
    cancelLoading.value = true
    try {
      await api.post(`/maintenance-requests/${record.value.id}/cancel`, { reason: cancelReason.value.trim() })
      toast.success('Request cancelled.')
      cancelOpen.value = false
      await load(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to cancel request.')
    } finally {
      cancelLoading.value = false
    }
  }

  return {
    record, loading, error, notFound, forbidden,
    editing, saving, editError, draft, validationErrors,
    attachments, attachmentsLoading,
    deleteAttachmentTarget, deleteAttachmentLoading, canDeleteAttachment,
    openDeleteAttachment, closeDeleteAttachment, doDeleteAttachment,
    isPending, isTerminal,
    canEdit, canApprove, canReject, canCancel,
    load, startEdit, cancelEdit, saveEdit,
    approveOpen, approveLoading, openApprove, doApprove,
    approveTechnicians, approveTechniciansLoading, selectedApproveTechId,
    rejectOpen, rejectLoading, rejectReason, openReject, doReject,
    cancelOpen, cancelLoading, cancelReason, openCancel, doCancel,
  }
}
