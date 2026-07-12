import { ref, computed, watch } from 'vue'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'
import type {
  WorkOrder,
  WorkOrderPart,
  Assignee,
  Attachment,
  AssetMeterReading,
  MissingField,
  WoFormFieldValue,
} from '@/types'

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
 *  GET    /admin/usage-reading-types        -> { data: Type[] }
 *  GET    /parts?search=                     -> cursor page           (parts picker)
 *  GET    /assets/{id}/meter-readings        -> { data: Reading[] }
 *  POST   /assets/{id}/meter-readings        -> { message, data }     (record reading)
 *  PATCH  /work-orders/{id}/form/fields/{f}  -> { data: field }        { pre_value?, post_value?, notes? }
 *  POST   /work-orders/{id}/form/sync        -> { message, data }     (accept sync-to-latest)
 *  POST   /work-orders/{id}/form/defer-sync  -> { message }           (dismiss for this session)
 */
export function useWorkOrderDetail() {
  const auth = useAuthStore()

  // ── Record + load state ──────────────────────────────────────────────────
  const record = ref<WorkOrder | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const notFound = ref(false)
  const forbidden = ref(false)

  // ── Attachments ──────────────────────────────────────────────────────────
  const attachments = ref<Attachment[]>([])
  const attachmentsLoading = ref(false)

  // ── Derived ──────────────────────────────────────────────────────────────
  const isOpen = computed(() => record.value?.status === 'open')
  const isInProgress = computed(() => record.value?.status === 'in_progress')
  const isCompleted = computed(() => record.value?.status === 'completed')
  const isTerminal = computed(
    () =>
      !!record.value && (record.value.status === 'closed' || record.value.status === 'cancelled'),
  )
  const isCancelled = computed(() => record.value?.status === 'cancelled')
  // Corrective-origin WOs carry a failure classification (re-asked at closure);
  // PM-origin WOs never do. The WO payload embeds the MR's `is_preventive` (not
  // `type`), so key off that: corrective ⇔ is_preventive === false.
  const isCorrectiveOrigin = computed(
    () => record.value?.maintenance_request?.is_preventive === false,
  )
  // The WO payload embeds `is_preventive` but not `type`; derive the display label
  // from it. null when no MR is linked.
  const originTypeLabel = computed<'Preventive' | 'Corrective' | null>(() => {
    const mr = record.value?.maintenance_request
    if (!mr) {
      return null
    }
    return mr.is_preventive ? 'Preventive' : 'Corrective'
  })

  // Lifecycle stepper model for the command bar. `cancelled` is off the linear
  // open→closed track, so the view renders a distinct marker instead (isCancelled)
  // and this collapses every step to `upcoming`.
  const lifecycleSteps = computed<
    { key: string; label: string; state: 'done' | 'current' | 'upcoming' }[]
  >(() => {
    const order = ['open', 'in_progress', 'completed', 'closed']
    const labels: Record<string, string> = {
      open: 'Open',
      in_progress: 'In progress',
      completed: 'Completed',
      closed: 'Closed',
    }
    const currentIdx = record.value ? order.indexOf(record.value.status) : -1
    return order.map((key, i) => ({
      key,
      label: labels[key] ?? key,
      state:
        currentIdx < 0
          ? 'upcoming'
          : i < currentIdx
            ? 'done'
            : i === currentIdx
              ? 'current'
              : 'upcoming',
    }))
  })

  // Live completion-gate status for the sidebar checklist. Mirrors the backend
  // gate exactly (WorkOrder::missingRequiredFields): only required fields count;
  // has_pre_post needs BOTH slots filled; a value is "filled" when non-null and
  // not whitespace (so a boolean stored as '0' counts as filled).
  const requiredFieldStatus = computed<{
    total: number
    done: number
    complete: boolean
    items: { uuid: string; label: string; done: boolean }[]
  }>(() => {
    const fields: WoFormFieldValue[] = record.value?.form?.fields ?? []
    const isEmpty = (v: string | null | undefined) => v == null || String(v).trim() === ''
    const isFilled = (f: WoFormFieldValue) =>
      f.has_pre_post ? !isEmpty(f.pre_value) && !isEmpty(f.post_value) : !isEmpty(f.post_value)
    const items = fields
      .filter((f) => f.is_required)
      .map((f) => ({ uuid: f.uuid, label: f.label, done: isFilled(f) }))
    const done = items.filter((i) => i.done).length
    return { total: items.length, done, complete: items.length > 0 && done === items.length, items }
  })

  const isAssignedToMe = computed(
    () => !!record.value?.assigned_to && record.value.assigned_to.id === auth.user?.id,
  )

  // Permissions (client UX hints — backend gate remains authoritative)
  const canEdit = computed(
    () =>
      !!record.value &&
      !isTerminal.value &&
      !isCompleted.value &&
      (auth.isAdminOrManager || isAssignedToMe.value),
  )
  // Assign while open; reassign while in progress too (the backend permits
  // assigning any non-closed/cancelled WO). Closed/cancelled stay locked.
  const canAssign = computed(
    () => !!record.value && (isOpen.value || isInProgress.value) && auth.isAdminOrManager,
  )
  const canStart = computed(
    () =>
      !!record.value &&
      isOpen.value &&
      !!record.value.assigned_to &&
      (auth.isAdminOrManager || isAssignedToMe.value),
  )
  const canComplete = computed(
    () => !!record.value && isInProgress.value && (auth.isAdminOrManager || isAssignedToMe.value),
  )
  const canClose = computed(() => !!record.value && isCompleted.value && auth.isAdminOrManager)
  const canCancel = computed(() => !!record.value && !isTerminal.value && auth.isAdminOrManager)
  const canSetAssetStatus = computed(
    () => !!record.value && !isTerminal.value && (auth.isAdminOrManager || isAssignedToMe.value),
  )
  // Stricter than the backend (which also allows edits while `completed`) —
  // matches FORM_REQUIREMENTS.md "read-only after completion".
  const canEditWoForm = computed(
    () =>
      !!record.value &&
      !isTerminal.value &&
      !isCompleted.value &&
      (auth.isAdminOrManager || isAssignedToMe.value),
  )

  // ── Edit state ────────────────────────────────────────────────────────────
  const editing = ref(false)
  const saving = ref(false)
  const editError = ref<string | null>(null)
  const draft = ref<{ description: string }>({ description: '' })
  const validationErrors = ref<Record<string, string[]> | null>(null)

  // ── Assign state ─────────────────────────────────────────────────────────
  const assignOpen = ref(false)
  const assignLoading = ref(false)
  const technicians = ref<Assignee[]>([])
  const techniciansLoading = ref(false)
  const selectedTechId = ref<number | null>(null)

  // ── Workflow transitions ─────────────────────────────────────────────────
  const startLoading = ref(false)
  const completeOpen = ref(false)
  const completeLoading = ref(false)
  const completionNotes = ref('')
  const closeOpen = ref(false)
  const closeLoading = ref(false)
  // Re-asked failure classification at closure (corrective-origin WOs only). Seeded
  // from the linked MR's current value so the reviewer sees the prior decision. Sent
  // to the API as `is_failure`.
  const closeIsFailure = ref<boolean | null>(null)
  const cancelOpen = ref(false)
  const cancelLoading = ref(false)
  const cancelReason = ref('')
  // Caller-chosen asset status on cancel: 'down' (still faulty) | 'active' (false alarm).
  const cancelAssetStatus = ref<'down' | 'active' | null>(null)

  // ── Parts state ──────────────────────────────────────────────────────────
  const addPartOpen = ref(false)
  const addPartLoading = ref(false)
  const partDraft = ref<{ quantity: number; notes: string }>({ quantity: 1, notes: '' })
  // Bound to <PartCombobox v-model>; selection shape { id, label }.
  const selectedPart = ref<{ id: number; label: string } | null>(null)
  const removeTarget = ref<number | null>(null)
  const removeLoading = ref(false)

  const parts = computed<WorkOrderPart[]>(() => record.value?.parts ?? [])

  // ── Readings state ───────────────────────────────────────────────────────
  const readingTypes = ref<{ id: number; name: string; unit: string }[]>([])
  const recordReadingOpen = ref(false)
  const readingLoading = ref(false)
  const readingDraft = ref<{
    typeId: number | null
    value: number | null
    readAt: string
    notes: string
  }>({
    typeId: null,
    value: null,
    readAt: new Date().toISOString().slice(0, 10),
    notes: '',
  })
  const assetReadings = ref<AssetMeterReading[]>([])
  const readingsLoading = ref(false)

  // ── Last-reading guard ─────────────────────────────────────────────────────
  // Most recent reading for the type selected in the record draft, so operators
  // see the previous value and can be warned before entering a lower one.
  const lastReadingForDraft = computed<{
    value: number
    readAt: string
    unit: string
    confirmed: boolean
  } | null>(() => {
    const typeId = readingDraft.value.typeId
    if (typeId == null) return null
    const forType = assetReadings.value.filter((r) => r.usage_reading_type_id === typeId)
    if (forType.length === 0) return null
    const latest = [...forType].sort((a, b) => {
      const byDate = (b.reading_at ?? '').localeCompare(a.reading_at ?? '')
      return byDate !== 0 ? byDate : b.id - a.id
    })[0]
    if (!latest) return null
    return {
      value: Number(latest.reading_value),
      readAt: latest.reading_at,
      unit: readingTypes.value.find((t) => t.id === typeId)?.unit ?? '',
      confirmed: latest.confirmed_at != null,
    }
  })

  // True when the drafted value is below the last recorded reading for its type.
  const readingBelowLast = computed<boolean>(() => {
    const last = lastReadingForDraft.value
    const v = readingDraft.value.value
    return last != null && v != null && v < last.value
  })

  // A lower-than-last value must be explicitly acknowledged before it can save.
  // Reset the acknowledgement whenever the type or value changes.
  const lowerReadingAcknowledged = ref(false)
  watch(
    () => [readingDraft.value.typeId, readingDraft.value.value],
    () => {
      lowerReadingAcknowledged.value = false
    },
  )

  // Edit + delete are role-gated (Admin/Manager/Technician). Confirmed readings
  // are immutable on the backend (409), so the UI hides actions for them.
  const canManageReadings = computed(() => auth.isAdminOrManager || auth.isTechnician)
  const editReadingOpen = ref(false)
  const editReadingLoading = ref(false)
  const editReadingDraft = ref<{
    id: number | null
    usage_reading_type_id: number | null
    value: number | null
    readAt: string
    notes: string
  }>({
    id: null,
    usage_reading_type_id: null,
    value: null,
    readAt: new Date().toISOString().slice(0, 10),
    notes: '',
  })
  const deleteReadingTarget = ref<number | null>(null)
  const deleteReadingLoading = ref(false)

  // Derived: "since last service" for PM-sourced WOs. Null until PM rule data
  // is available; the view populates this when the WO's source MR carries a
  // reading-triggered PM rule. Left as a placeholder for the initial build.
  const sinceLastService = computed<{
    type: string
    since: number
    interval: number
    unit: string
  } | null>(() => null)

  // ── Asset status state ───────────────────────────────────────────────────
  const assetStatusOpen = ref(false)
  const assetStatusLoading = ref(false)
  const selectedStatus = ref<string | null>(null)

  // ── Upload state ─────────────────────────────────────────────────────────
  const uploadOpen = ref(false)
  const uploadLoading = ref(false)
  const uploadFiles = ref<File[]>([])

  // ── Attachment delete state ──────────────────────────────────────────────
  const deleteAttachmentTarget = ref<number | null>(null)
  const deleteAttachmentLoading = ref(false)

  // ── WO Form state ────────────────────────────────────────────────────────
  const syncDeferred = ref(false) // session-scoped: hides the sync banner until reload
  const missingFields = ref<Set<string>>(new Set()) // uuids from the last 422 completion-gate response

  // ══════════════════════════════════════════════════════════════════════════
  //  Load
  // ══════════════════════════════════════════════════════════════════════════
  // `silent` refreshes the record in place without flipping `loading`, which
  // would otherwise swap the whole view out for the loading state and remount
  // it — resetting scroll to the top. Post-mutation reloads (field autosave,
  // workflow transitions) pass silent:true; the initial mount / route change
  // leaves it false so the spinner still shows on first load.
  async function load(id: number | string, { silent = false }: { silent?: boolean } = {}) {
    // Only reset WO Form session state when switching to a different WO — a
    // reload of the *same* WO happens after every field autosave/sync/defer,
    // and resetting unconditionally would immediately undo that action's own
    // state change (e.g. defer setting syncDeferred=true) or wipe still-valid
    // missing-field highlights the user hasn't addressed yet.
    const isNewRecord = record.value === null || String(record.value.id) !== String(id)
    if (!silent) {
      loading.value = true
    }
    error.value = null
    notFound.value = false
    forbidden.value = false
    if (isNewRecord) {
      syncDeferred.value = false
      missingFields.value = new Set()
    }
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
      await api.patch(`/work-orders/${record.value.id}`, {
        description: draft.value.description || null,
      })
      editing.value = false
      toast.success('Changes saved.')
      // Reload the full record rather than trusting the PATCH response, which
      // omits eager-loaded relations (asset, maintenance_request, …) and would
      // otherwise blank them out and crash the template.
      await load(record.value.id, { silent: true })
    } catch (e) {
      if (e instanceof ApiError) {
        if (e.validationErrors) validationErrors.value = e.validationErrors
        else if (e.status === 403)
          editError.value = 'You do not have permission to edit this work order.'
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
        const res = await api.get<{
          data: { id: number; name: string; role?: { code: string }; is_active: boolean }[]
        }>('/admin/users')
        technicians.value = (res.data ?? [])
          .filter(
            (u) =>
              u.is_active &&
              (u.role?.code === 'technician' || u.role?.code === 'maintenance_manager'),
          )
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
      await load(record.value.id, { silent: true })
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
      await load(record.value.id, { silent: true })
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to start work order.')
    } finally {
      startLoading.value = false
    }
  }

  function openComplete() {
    completionNotes.value = ''
    completeOpen.value = true
  }
  async function doComplete() {
    if (!record.value) return
    completeLoading.value = true
    try {
      await api.post(`/work-orders/${record.value.id}/complete`, {
        completion_notes: completionNotes.value.trim() || null,
      })
      toast.success('Work order marked completed.')
      completeOpen.value = false
      missingFields.value = new Set()
      await load(record.value.id, { silent: true })
    } catch (e) {
      if (e instanceof ApiError && e.status === 422 && Array.isArray(e.data?.missing)) {
        missingFields.value = new Set((e.data.missing as MissingField[]).map((m) => m.uuid))
        toast.error('Complete required form fields first.')
      } else {
        toast.error(e instanceof ApiError ? e.message : 'Failed to complete work order.')
      }
    } finally {
      completeLoading.value = false
    }
  }

  function openClose() {
    // Seed the toggle with the prior review-time decision so the reviewer can
    // confirm or override it after physical inspection.
    closeIsFailure.value = record.value?.maintenance_request?.is_failure ?? null
    closeOpen.value = true
  }
  async function doClose() {
    if (!record.value) return
    closeLoading.value = true
    try {
      // Only send `is_failure` for corrective-origin WOs when a value is chosen —
      // never send null, which would clobber the review-time classification. The
      // key is omitted entirely for PM WOs and when unset.
      const payload =
        isCorrectiveOrigin.value && closeIsFailure.value !== null
          ? { is_failure: closeIsFailure.value }
          : undefined
      await api.post(`/work-orders/${record.value.id}/close`, payload)
      toast.success('Work order closed.')
      closeOpen.value = false
      await load(record.value.id, { silent: true })
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to close work order.')
    } finally {
      closeLoading.value = false
    }
  }

  function openCancel() {
    cancelReason.value = ''
    cancelAssetStatus.value = null
    cancelOpen.value = true
  }
  async function doCancel() {
    if (!record.value || !cancelReason.value.trim() || cancelAssetStatus.value === null) return
    cancelLoading.value = true
    try {
      await api.post(`/work-orders/${record.value.id}/cancel`, {
        reason: cancelReason.value.trim(),
        asset_status: cancelAssetStatus.value,
      })
      toast.success('Work order cancelled.')
      cancelOpen.value = false
      await load(record.value.id, { silent: true })
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
    partDraft.value = { quantity: 1, notes: '' }
    selectedPart.value = null
    addPartOpen.value = true
  }

  async function doAddPart() {
    if (!record.value || !selectedPart.value) return
    addPartLoading.value = true
    try {
      await api.post(`/work-orders/${record.value.id}/parts`, {
        part_id: selectedPart.value.id,
        quantity: partDraft.value.quantity,
        notes: partDraft.value.notes || null,
      })
      toast.success('Part added.')
      addPartOpen.value = false
      await load(record.value.id, { silent: true })
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to add part.')
    } finally {
      addPartLoading.value = false
    }
  }

  function openRemovePart(partLineId: number) {
    removeTarget.value = partLineId
  }
  async function doRemovePart() {
    if (!record.value || !removeTarget.value) return
    removeLoading.value = true
    try {
      await api.delete(`/work-orders/${record.value.id}/parts/${removeTarget.value}`)
      toast.success('Part removed.')
      removeTarget.value = null
      await load(record.value.id, { silent: true })
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
    readingDraft.value = {
      typeId: null,
      value: null,
      readAt: new Date().toISOString().slice(0, 10),
      notes: '',
    }
    recordReadingOpen.value = true
    void ensureReadingTypes()
  }

  async function doRecordReading() {
    if (!record.value || !readingDraft.value.typeId || readingDraft.value.value == null) return
    // Warn + require confirm: a lower-than-last value must be acknowledged first.
    if (readingBelowLast.value && !lowerReadingAcknowledged.value) return
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
      const res = await api.get<{ data: AssetMeterReading[] }>(
        `/assets/${record.value.asset.id}/meter-readings`,
      )
      assetReadings.value = res.data ?? []
    } catch {
      assetReadings.value = []
    } finally {
      readingsLoading.value = false
    }
  }

  // Shared lazy loader for the reading-type catalogue — needed by both the
  // Record and Edit dialogs to render the type name.
  async function ensureReadingTypes() {
    if (readingTypes.value.length > 0) return
    try {
      // Reading types live under the admin namespace (GET /admin/usage-reading-types).
      const res = await api.get<{ data: { id: number; name: string; unit: string }[] }>(
        '/admin/usage-reading-types',
      )
      readingTypes.value = (res.data ?? []).filter((t) => t.name)
    } catch {
      /* silent */
    }
  }

  function openEditReading(r: AssetMeterReading) {
    editReadingDraft.value = {
      id: r.id,
      usage_reading_type_id: r.usage_reading_type_id,
      value: r.reading_value == null ? null : Number(r.reading_value),
      readAt: (r.reading_at ?? '').slice(0, 10),
      notes: r.notes ?? '',
    }
    editReadingOpen.value = true
    void ensureReadingTypes()
  }

  async function doEditReading() {
    if (!record.value || editReadingDraft.value.id === null) return
    editReadingLoading.value = true
    try {
      await api.patch(
        `/assets/${record.value.asset.id}/meter-readings/${editReadingDraft.value.id}`,
        {
          reading_value: editReadingDraft.value.value,
          reading_at: editReadingDraft.value.readAt,
          notes: editReadingDraft.value.notes || null,
        },
      )
      toast.success('Meter reading updated.')
      editReadingOpen.value = false
      await loadAssetReadings()
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        toast.error('Confirmed readings cannot be changed.')
      } else {
        toast.error(e instanceof ApiError ? e.message : 'Failed to update meter reading.')
      }
    } finally {
      editReadingLoading.value = false
    }
  }

  function openDeleteReading(id: number) {
    deleteReadingTarget.value = id
  }

  async function doDeleteReading() {
    if (!record.value || deleteReadingTarget.value === null) return
    deleteReadingLoading.value = true
    try {
      await api.delete(
        `/assets/${record.value.asset.id}/meter-readings/${deleteReadingTarget.value}`,
      )
      toast.success('Meter reading deleted.')
      deleteReadingTarget.value = null
      await loadAssetReadings()
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        toast.error('Confirmed readings cannot be deleted.')
      } else {
        toast.error(e instanceof ApiError ? e.message : 'Failed to delete meter reading.')
      }
    } finally {
      deleteReadingLoading.value = false
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
      await load(record.value.id, { silent: true })
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to update asset status.')
    } finally {
      assetStatusLoading.value = false
    }
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  WO Form
  // ══════════════════════════════════════════════════════════════════════════
  async function updateFieldValue(
    fieldId: number,
    uuid: string,
    payload: { pre_value?: string | null; post_value?: string | null; notes?: string | null },
  ) {
    if (!record.value) return
    try {
      await api.patch(`/work-orders/${record.value.id}/form/fields/${fieldId}`, payload)
      missingFields.value.delete(uuid)
      await load(record.value.id, { silent: true })
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to save form field.')
    }
  }

  async function syncForm() {
    if (!record.value) return
    try {
      await api.post(`/work-orders/${record.value.id}/form/sync`)
      toast.success('Form synced to latest template version.')
      syncDeferred.value = false
      missingFields.value = new Set()
      await load(record.value.id, { silent: true })
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to sync form.')
    }
  }

  async function deferFormSync() {
    if (!record.value) return
    try {
      await api.post(`/work-orders/${record.value.id}/form/defer-sync`)
      syncDeferred.value = true
      await load(record.value.id, { silent: true })
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to defer sync.')
    }
  }

  // ══════════════════════════════════════════════════════════════════════════
  //  Upload
  // ══════════════════════════════════════════════════════════════════════════
  function openUpload() {
    uploadFiles.value = []
    uploadOpen.value = true
  }
  function addFiles(files: FileList | File[]) {
    uploadFiles.value.push(...Array.from(files))
  }
  function removeFile(i: number) {
    uploadFiles.value.splice(i, 1)
  }

  async function doUpload() {
    if (!record.value || uploadFiles.value.length === 0) return
    uploadLoading.value = true
    try {
      for (const f of uploadFiles.value) {
        const form = new FormData()
        form.append('file', f)
        await api.upload(`/work-orders/${record.value.id}/attachments`, form)
      }
      toast.success(
        uploadFiles.value.length === 1
          ? 'Attachment uploaded.'
          : `${uploadFiles.value.length} attachments uploaded.`,
      )
      uploadOpen.value = false
      uploadFiles.value = []
      await loadAttachments(record.value.id)
      await load(record.value.id, { silent: true })
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : 'Failed to upload attachments.')
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
    attachments,
    attachmentsLoading,
    // Derived + permissions
    isOpen,
    isInProgress,
    isCompleted,
    isTerminal,
    isCancelled,
    isCorrectiveOrigin,
    originTypeLabel,
    isAssignedToMe,
    lifecycleSteps,
    requiredFieldStatus,
    canEdit,
    canAssign,
    canStart,
    canComplete,
    canClose,
    canCancel,
    canSetAssetStatus,
    canEditWoForm,
    // Edit
    editing,
    saving,
    editError,
    draft,
    validationErrors,
    startEdit,
    cancelEdit,
    saveEdit,
    // Assign
    assignOpen,
    assignLoading,
    technicians,
    techniciansLoading,
    selectedTechId,
    openAssign,
    doAssign,
    // Workflow
    startLoading,
    doStart,
    completeOpen,
    completeLoading,
    completionNotes,
    openComplete,
    doComplete,
    closeOpen,
    closeLoading,
    closeIsFailure,
    openClose,
    doClose,
    cancelOpen,
    cancelLoading,
    cancelReason,
    cancelAssetStatus,
    openCancel,
    doCancel,
    // Parts
    addPartOpen,
    addPartLoading,
    partDraft,
    selectedPart,
    openAddPart,
    doAddPart,
    removeTarget,
    removeLoading,
    openRemovePart,
    doRemovePart,
    parts,
    // Readings
    readingTypes,
    recordReadingOpen,
    readingLoading,
    readingDraft,
    assetReadings,
    readingsLoading,
    lastReadingForDraft,
    readingBelowLast,
    lowerReadingAcknowledged,
    sinceLastService,
    openRecordReading,
    doRecordReading,
    loadAssetReadings,
    canManageReadings,
    editReadingOpen,
    editReadingLoading,
    editReadingDraft,
    openEditReading,
    doEditReading,
    deleteReadingTarget,
    deleteReadingLoading,
    openDeleteReading,
    doDeleteReading,
    // Asset status
    assetStatusOpen,
    assetStatusLoading,
    selectedStatus,
    openSetAssetStatus,
    doSetAssetStatus,
    // Upload
    uploadOpen,
    uploadLoading,
    uploadFiles,
    openUpload,
    addFiles,
    removeFile,
    doUpload,
    // Attachment delete
    deleteAttachmentTarget,
    deleteAttachmentLoading,
    openDeleteAttachment,
    doDeleteAttachment,
    // WO Form
    syncDeferred,
    missingFields,
    updateFieldValue,
    syncForm,
    deferFormSync,
    // Init
    load,
    loadAttachments,
  }
}
