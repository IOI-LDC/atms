import { ref, computed } from 'vue'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'
import type { Part, Attachment } from '@/types'

interface PartEditDraft {
  name: string
  available_quantity: string
  maintenance_category_id: number | null
  size_inches: string
  is_active: boolean
}

/**
 * Owns the state and actions for the Part Detail page.
 *
 * Backend contract (see docs/atms/04-technical/BACKEND_API_REFERENCE.md):
 *  GET   /api/parts/{id}            -> { data: PartResource }  (403 on an
 *       inactive part unless Admin/Manager)
 *  PATCH /api/parts/{id}            -> { data: PartResource }  (Admin/Manager)
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
  const canEdit = computed(() => auth.isAdminOrManager)
  // Matches AttachmentPolicy::uploadToPart.
  const canUploadAttachment = computed(
    () => auth.isAdminOrManager || auth.isTechnician || auth.isLogistics,
  )
  const canViewErpMeta = computed(() => auth.isAdminOrManager)
  const canViewErpRaw = computed(() => auth.isAdmin)

  // ── Edit state ────────────────────────────────────────────────────────────
  const editOpen = ref(false)
  const confirmEditOpen = ref(false)
  const saving = ref(false)
  const editError = ref<string | null>(null)
  const validationErrors = ref<Record<string, string[]> | null>(null)
  const draft = ref<PartEditDraft>({
    name: '',
    available_quantity: '0',
    maintenance_category_id: null,
    size_inches: '',
    is_active: true,
  })

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
  //  Edit
  // ══════════════════════════════════════════════════════════════════════════
  function openEdit() {
    if (!record.value) return
    draft.value = {
      name: record.value.name,
      available_quantity: String(record.value.available_quantity),
      maintenance_category_id: record.value.maintenance_category?.id ?? null,
      size_inches: record.value.size_inches ?? '',
      is_active: record.value.is_active,
    }
    validationErrors.value = null
    editError.value = null
    editOpen.value = true
  }

  function closeEdit() {
    editOpen.value = false
    validationErrors.value = null
    editError.value = null
  }

  function requestSave() {
    const errors: Record<string, string[]> = {}
    const quantity = Number(draft.value.available_quantity)

    if (!draft.value.name.trim()) {
      errors.name = ['Part name is required.']
    }
    if (
      draft.value.available_quantity.trim() === '' ||
      !Number.isFinite(quantity) ||
      quantity < 0
    ) {
      errors.available_quantity = ['Available quantity must be zero or greater.']
    }

    validationErrors.value = Object.keys(errors).length > 0 ? errors : null
    if (validationErrors.value) return

    confirmEditOpen.value = true
  }

  async function doSave() {
    if (!record.value) return
    saving.value = true
    validationErrors.value = null
    editError.value = null
    try {
      const res = await api.patch<{ data: Part }>(`/parts/${record.value.id}`, {
        name: draft.value.name.trim(),
        available_quantity: Number(draft.value.available_quantity),
        maintenance_category_id: draft.value.maintenance_category_id,
        size_inches: draft.value.size_inches || null,
        is_active: draft.value.is_active,
      })
      record.value = res.data
      editOpen.value = false
      confirmEditOpen.value = false
      toast.success('Part updated.')
    } catch (e) {
      confirmEditOpen.value = false
      if (e instanceof ApiError) {
        if (e.validationErrors) validationErrors.value = e.validationErrors
        else if (e.status === 403) editError.value = 'You do not have permission to edit this part.'
        else editError.value = e.message
      } else {
        editError.value = 'Failed to save changes.'
      }
    } finally {
      saving.value = false
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
    canEdit,
    canUploadAttachment,
    canViewErpMeta,
    canViewErpRaw,
    // Edit
    editOpen,
    confirmEditOpen,
    saving,
    editError,
    validationErrors,
    draft,
    openEdit,
    closeEdit,
    requestSave,
    doSave,
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
