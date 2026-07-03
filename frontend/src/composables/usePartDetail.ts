import { ref, computed } from 'vue'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'
import type { Part, Attachment } from '@/types'

/**
 * Owns the state and actions for the Part Detail page. Read-only reference
 * data + attachments — editing is out of scope for this build (PATCH exists
 * on the backend for a future build to wire up).
 *
 * Backend contract (see docs/atms/04-technical/BACKEND_API_REFERENCE.md):
 *  GET  /api/parts/{id}             -> { data: PartResource }  (403 on an
 *       inactive part unless Admin/Manager)
 *  GET  /api/parts/{id}/attachments -> { data: Attachment[] }
 *  POST /api/parts/{id}/attachments -> attachment  (multipart upload)
 */
export function usePartDetail() {
  const auth = useAuthStore()

  // ── Record + load state ──────────────────────────────────────────────────
  const record = ref<Part | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const notFound = ref(false)
  const forbidden = ref(false)

  // ── Permissions (client UX hints — backend policy remains authoritative) ──
  // Matches AttachmentPolicy::uploadToPart.
  const canUploadAttachment = computed(
    () => auth.isAdminOrManager || auth.isTechnician || auth.isLogistics,
  )
  const canViewErpMeta = computed(() => auth.isAdminOrManager)
  const canViewErpRaw = computed(() => auth.isAdmin)

  // ── Attachments ───────────────────────────────────────────────────────────
  const attachments = ref<Attachment[]>([])
  const attachmentsLoading = ref(false)
  const uploadOpen = ref(false)
  const uploadLoading = ref(false)
  const uploadFiles = ref<File[]>([])

  // ── Attachment delete ─────────────────────────────────────────────────────
  const deleteAttachmentTarget = ref<number | null>(null)
  const deleteAttachmentLoading = ref(false)

  // ══════════════════════════════════════════════════════════════════════════
  //  Load
  // ══════════════════════════════════════════════════════════════════════════
  async function load(id: number | string) {
    loading.value = true
    error.value = null
    notFound.value = false
    forbidden.value = false
    try {
      const res = await api.get<{ data: Part }>(`/parts/${id}`)
      record.value = res.data
    } catch (e) {
      record.value = null
      if (e instanceof ApiError) {
        if (e.status === 404) notFound.value = true
        else if (e.status === 403) forbidden.value = true
        else error.value = e.message
      } else {
        error.value = 'Failed to load part.'
      }
    } finally {
      loading.value = false
    }
  }

  async function loadAttachments(id: number | string) {
    attachmentsLoading.value = true
    try {
      const res = await api.get<{ data: Attachment[] }>(`/parts/${id}/attachments`)
      attachments.value = res.data ?? []
    } catch {
      attachments.value = []
    } finally {
      attachmentsLoading.value = false
    }
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  Attachments upload
  // ══════════════════════════════════════════════════════════════════════════
  function openUpload() {
    uploadFiles.value = []
    uploadOpen.value = true
  }

  function addUploadFiles(files: FileList | File[]) {
    uploadFiles.value.push(...Array.from(files))
  }

  function removeUploadFile(i: number) {
    uploadFiles.value.splice(i, 1)
  }

  async function doUpload(partId: number | string) {
    if (uploadFiles.value.length === 0) return
    uploadLoading.value = true
    try {
      for (const f of uploadFiles.value) {
        const form = new FormData()
        form.append('file', f)
        await api.upload(`/parts/${partId}/attachments`, form)
      }
      toast.success(
        uploadFiles.value.length === 1
          ? 'Attachment uploaded.'
          : `${uploadFiles.value.length} attachments uploaded.`,
      )
      uploadOpen.value = false
      uploadFiles.value = []
      await loadAttachments(partId)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to upload attachment.')
    } finally {
      uploadLoading.value = false
    }
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  Attachment delete  (generic by id: DELETE /attachments/{id})
  // ══════════════════════════════════════════════════════════════════════════
  function openDeleteAttachment(id: number) {
    deleteAttachmentTarget.value = id
  }
  async function doDeleteAttachment() {
    if (!record.value || deleteAttachmentTarget.value === null) return
    deleteAttachmentLoading.value = true
    try {
      await api.delete(`/attachments/${deleteAttachmentTarget.value}`)
      toast.success('Attachment deleted.')
      deleteAttachmentTarget.value = null
      await loadAttachments(record.value.id)
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to delete attachment.')
    } finally {
      deleteAttachmentLoading.value = false
    }
  }

  return {
    // Load
    record,
    loading,
    error,
    notFound,
    forbidden,
    load,
    // Permissions
    canUploadAttachment,
    canViewErpMeta,
    canViewErpRaw,
    // Attachments
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
  }
}
