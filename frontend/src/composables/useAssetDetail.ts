import { ref, computed } from 'vue'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'
import type {
  Asset,
  AssetLocationHistoryItem,
  AssetMeterReading,
  MaintenanceHistoryItem,
  Attachment,
  Location,
} from '@/types'

/**
 * Draft shape for the Edit Asset sheet.
 * Covers all PATCH /api/assets/{asset} fields except parent_asset_id and
 * maintenance_sub_status — those are Phase 2 (assembly).
 *
 * asset_tag: set freely when current tag is null (Admin/Manager). Once a tag
 * is saved it is immutable — override requires Admin + reason (separate flow).
 */
interface AssetEditDraft {
  name: string
  description: string
  serial_number: string
  model: string
  manufacturer: string
  operational_status: string
  maintenance_status: string
  maintenance_sub_status: string
  is_active: boolean
  current_location_id: number | null
  location_notes: string
  asset_kind: string
  asset_tag: string
  asset_tag_override_reason: string
}

/**
 * Owns the state and actions for the Asset Detail page.
 *
 * Backend contract (see docs/04-technical/BACKEND_API_REFERENCE.md):
 *  GET   /api/assets/{id}                     -> { data: AssetResource }
 *  PATCH /api/assets/{id}                     -> { data: AssetResource }
 *  GET   /api/assets/{id}/location-history    -> { data: AssetLocationHistoryItem[] }
 *  GET   /api/assets/{id}/maintenance-history -> { data: MaintenanceHistoryItem[] }  (403 for Logistics)
 *  GET   /api/assets/{id}/meter-readings      -> { data: AssetMeterReading[] }
 *  GET   /api/assets/{id}/attachments         -> { data: Attachment[] }
 *  POST  /api/assets/{id}/attachments         -> attachment  (multipart upload)
 *  GET   /api/admin/locations                 -> { data: Location[] }  (Admin/Manager — for edit form)
 */
export function useAssetDetail() {
  const auth = useAuthStore()

  // ── Record + load state ──────────────────────────────────────────────────
  const record = ref<Asset | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const notFound = ref(false)
  const forbidden = ref(false)

  // ── Permissions (client UX hints — backend gate remains authoritative) ────
  const canEdit = computed(() => auth.isAdminOrManager)
  // Booking is togglable by Admin, Manager, and Logistics (backend gate authoritative).
  const canToggleBooking = computed(() => auth.isAdminOrManager || auth.isLogistics)
  // Logistics cannot see ERP reference fields or maintenance history (API 403)
  const canViewSensitive = computed(() => !auth.isLogistics)

  // ── Booking state ───────────────────────────────────────────────────────────
  const bookingConfirmOpen = ref(false)
  const bookingLoading = ref(false)

  // ── Edit state ────────────────────────────────────────────────────────────
  const editOpen = ref(false)
  const confirmEditOpen = ref(false)
  const saving = ref(false)
  const editError = ref<string | null>(null)
  const validationErrors = ref<Record<string, string[]> | null>(null)
  const draft = ref<AssetEditDraft>({
    name: '',
    description: '',
    serial_number: '',
    model: '',
    manufacturer: '',
    operational_status: 'active',
    maintenance_status: 'enrolled',
    maintenance_sub_status: '',
    is_active: true,
    current_location_id: null,
    location_notes: '',
    asset_kind: 'asset',
    asset_tag: '',
    asset_tag_override_reason: '',
  })

  // ── Suggest-tag state ─────────────────────────────────────────────────────
  const suggestTagLoading = ref(false)
  const suggestTagCollision = ref(false)

  // ── Locations (for edit form — Admin/Manager only) ────────────────────────
  const locations = ref<Location[]>([])
  const locationsLoading = ref(false)

  // ── Location history ──────────────────────────────────────────────────────
  const locationHistory = ref<AssetLocationHistoryItem[]>([])
  const locationHistoryLoading = ref(false)

  // ── Maintenance history ───────────────────────────────────────────────────
  const maintenanceHistory = ref<MaintenanceHistoryItem[]>([])
  const maintenanceHistoryLoading = ref(false)

  // ── Usage readings (read-only on asset detail) ────────────────────────────
  const readings = ref<AssetMeterReading[]>([])
  const readingsLoading = ref(false)

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
  //  Primary load
  // ══════════════════════════════════════════════════════════════════════════
  async function load(id: number | string) {
    loading.value = true
    error.value = null
    notFound.value = false
    forbidden.value = false
    try {
      const res = await api.get<{ data: Asset }>(`/assets/${id}`)
      record.value = res.data
    } catch (e) {
      record.value = null
      if (e instanceof ApiError) {
        if (e.status === 404) notFound.value = true
        else if (e.status === 403) forbidden.value = true
        else error.value = e.message
      } else {
        error.value = 'Failed to load asset.'
      }
    } finally {
      loading.value = false
    }
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  Secondary section loaders
  // ══════════════════════════════════════════════════════════════════════════
  async function loadLocationHistory(id: number | string) {
    locationHistoryLoading.value = true
    try {
      // The API returns the resolved `from_location` / `to_location` objects
      // ({ id, name }) directly — consume them as-is. (Earlier code tried to
      // re-resolve names from the Admin-only /admin/locations endpoint using
      // location-id fields the response doesn't expose, which both 403'd for
      // non-admins and clobbered the real objects → "Location #undefined".)
      const res = await api.get<{ data: AssetLocationHistoryItem[] }>(
        `/assets/${id}/location-history`,
      )
      locationHistory.value = res.data ?? []
    } catch {
      locationHistory.value = []
    } finally {
      locationHistoryLoading.value = false
    }
  }

  async function loadMaintenanceHistory(id: number | string) {
    // Logistics receives 403 — silently ignore, leave array empty.
    maintenanceHistoryLoading.value = true
    try {
      const res = await api.get<{ data: MaintenanceHistoryItem[] }>(
        `/assets/${id}/maintenance-history`,
      )
      maintenanceHistory.value = res.data ?? []
    } catch {
      maintenanceHistory.value = []
    } finally {
      maintenanceHistoryLoading.value = false
    }
  }

  async function loadReadings(id: number | string) {
    readingsLoading.value = true
    try {
      const res = await api.get<{ data: AssetMeterReading[] }>(`/assets/${id}/meter-readings`)
      readings.value = res.data ?? []
    } catch {
      readings.value = []
    } finally {
      readingsLoading.value = false
    }
  }

  async function loadAttachments(id: number | string) {
    attachmentsLoading.value = true
    try {
      const res = await api.get<{ data: Attachment[] }>(`/assets/${id}/attachments`)
      attachments.value = res.data ?? []
    } catch {
      attachments.value = []
    } finally {
      attachmentsLoading.value = false
    }
  }

  async function loadLocations() {
    if (locations.value.length > 0) return // already cached
    locationsLoading.value = true
    try {
      const res = await api.get<{ data: Location[] }>('/admin/locations')
      locations.value = (res.data ?? []).filter((l) => l.is_active)
    } catch {
      locations.value = []
    } finally {
      locationsLoading.value = false
    }
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  Edit
  // ══════════════════════════════════════════════════════════════════════════
  function openEdit() {
    if (!record.value) return
    draft.value = {
      name: record.value.name,
      description: record.value.description ?? '',
      serial_number: record.value.serial_number ?? '',
      model: record.value.model ?? '',
      manufacturer: record.value.manufacturer ?? '',
      operational_status: record.value.operational_status ?? 'active',
      maintenance_status: record.value.maintenance_status ?? 'enrolled',
      maintenance_sub_status: record.value.maintenance_sub_status ?? '',
      is_active: record.value.is_active ?? true,
      current_location_id: record.value.current_location?.id ?? null,
      location_notes: '',
      asset_kind: record.value.asset_kind ?? 'asset',
      // Only pre-fill when already set; otherwise leave blank for user to assign
      asset_tag: record.value.asset_tag ?? '',
      asset_tag_override_reason: '',
    }
    validationErrors.value = null
    editError.value = null
    suggestTagCollision.value = false
    editOpen.value = true
    void loadLocations()
  }

  function closeEdit() {
    editOpen.value = false
    validationErrors.value = null
    editError.value = null
  }

  /** Validates then opens the confirmation dialog. */
  function requestSave() {
    if (!draft.value.name.trim()) {
      validationErrors.value = { name: ['Asset name is required.'] }
      return
    }
    validationErrors.value = null
    confirmEditOpen.value = true
  }

  async function doSave() {
    if (!record.value) return
    saving.value = true
    validationErrors.value = null
    editError.value = null
    try {
      const payload: Record<string, unknown> = {
        name: draft.value.name.trim(),
        description: draft.value.description.trim() || null,
        serial_number: draft.value.serial_number.trim() || null,
        model: draft.value.model.trim() || null,
        manufacturer: draft.value.manufacturer.trim() || null,
        operational_status: draft.value.operational_status,
        maintenance_status: draft.value.maintenance_status,
        maintenance_sub_status: draft.value.maintenance_sub_status.trim() || null,
        current_location_id: draft.value.current_location_id,
        location_notes: draft.value.location_notes.trim() || null,
        asset_kind: draft.value.asset_kind,
      }
      // is_active is Admin/Manager-only per the API
      if (auth.isAdminOrManager) {
        payload.is_active = draft.value.is_active
      }
      // asset_tag: set freely when null. When already set, Admin may override
      // with a reason (per STATE.md / ASSET_TAG.md Rule 2).
      const newTag = draft.value.asset_tag.trim()
      if (!record.value.asset_tag && newTag) {
        payload.asset_tag = newTag
      } else if (
        record.value.asset_tag &&
        newTag &&
        newTag !== record.value.asset_tag &&
        auth.isAdmin
      ) {
        payload.asset_tag = newTag
        payload.asset_tag_override_reason = draft.value.asset_tag_override_reason.trim()
      }

      const res = await api.patch<{ data: Asset }>(`/assets/${record.value.id}`, payload)
      record.value = res.data
      editOpen.value = false
      confirmEditOpen.value = false
      toast.success('Asset updated.')
    } catch (e) {
      confirmEditOpen.value = false
      if (e instanceof ApiError) {
        if (e.validationErrors) validationErrors.value = e.validationErrors
        else if (e.status === 403)
          editError.value = 'You do not have permission to edit this asset.'
        else editError.value = e.message
      } else {
        editError.value = 'Failed to save changes.'
      }
    } finally {
      saving.value = false
    }
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  Suggest tag
  // ══════════════════════════════════════════════════════════════════════════
  /**
   * Calls POST /api/assets/{id}/suggest-tag to auto-generate the L-BBB-CCC-XXXX
   * tag from the asset's ERP subclass code, description, and serial number.
   * Pre-fills the draft if no collision; sets suggestTagCollision=true otherwise
   * so the view can prompt the user to enter the tag manually.
   */
  async function suggestTag() {
    if (!record.value) return
    suggestTagLoading.value = true
    suggestTagCollision.value = false
    try {
      const res = await api.post<{ asset_tag: string | null; collision: boolean }>(
        `/assets/${record.value.id}/suggest-tag`,
      )
      if (res.collision || !res.asset_tag) {
        suggestTagCollision.value = true
      } else {
        draft.value.asset_tag = res.asset_tag
      }
    } catch {
      // Non-blocking — user can still type a tag manually
    } finally {
      suggestTagLoading.value = false
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

  async function doUpload(assetId: number | string) {
    if (uploadFiles.value.length === 0) return
    uploadLoading.value = true
    try {
      for (const f of uploadFiles.value) {
        const form = new FormData()
        form.append('file', f)
        await api.upload(`/assets/${assetId}/attachments`, form)
      }
      toast.success(
        uploadFiles.value.length === 1
          ? 'Attachment uploaded.'
          : `${uploadFiles.value.length} attachments uploaded.`,
      )
      uploadOpen.value = false
      uploadFiles.value = []
      await loadAttachments(assetId)
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

  // ── Booking actions ─────────────────────────────────────────────────────────
  function requestToggleBooking() {
    bookingConfirmOpen.value = true
  }
  function closeBookingConfirm() {
    bookingConfirmOpen.value = false
  }

  async function doToggleBooking() {
    if (!record.value) {
      return
    }
    bookingLoading.value = true
    const endpoint = record.value.is_booked ? 'unbook' : 'book'
    try {
      const res = await api.post<{ data: Asset }>(`/assets/${record.value.id}/${endpoint}`)
      record.value = res.data
      bookingConfirmOpen.value = false
      toast.success(endpoint === 'book' ? 'Asset booked.' : 'Asset unbooked.')
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to update booking.')
    } finally {
      bookingLoading.value = false
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
    loadLocationHistory,
    loadMaintenanceHistory,
    loadReadings,
    loadAttachments,
    // Permissions
    canEdit,
    canViewSensitive,
    canToggleBooking,
    // Booking
    bookingConfirmOpen,
    bookingLoading,
    requestToggleBooking,
    closeBookingConfirm,
    doToggleBooking,
    // Edit
    editOpen,
    confirmEditOpen,
    saving,
    editError,
    validationErrors,
    draft,
    locations,
    locationsLoading,
    openEdit,
    closeEdit,
    requestSave,
    doSave,
    // Suggest tag
    suggestTagLoading,
    suggestTagCollision,
    suggestTag,
    // Location history
    locationHistory,
    locationHistoryLoading,
    // Maintenance history
    maintenanceHistory,
    maintenanceHistoryLoading,
    // Readings
    readings,
    readingsLoading,
    // Attachments
    attachments,
    attachmentsLoading,
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
