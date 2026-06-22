import { ref, computed } from 'vue'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'
import { useAssetSearch } from './useAssetSearch'
import { createCursorSource } from '@/lib/dataTableSource'
import type { MaintenanceRequest } from '@/types'
import type { ServerDataOptions } from '@ioi-dev/vue-table'

export function useMaintenanceRequests() {
  const auth = useAuthStore()

  // ── List data sources (ioi-vue-table server mode) ─────────────────────────────
  // Each tab renders its own <Table> with one of these as :server-options. The
  // table owns rows + cursor + loading; this composable only describes the
  // endpoint + fixed base params (tab semantics). See lib/dataTableSource.ts.
  // Inline approve/reject/cancel were removed — all actions live on the detail
  // page (useMaintenanceRequestDetail).

  const allMrSource: ServerDataOptions<MaintenanceRequest> =
    createCursorSource<MaintenanceRequest>({
      endpoint: '/maintenance-requests',
      baseParams: { sort: 'created_at:desc' },
    })

  const awaitingSource: ServerDataOptions<MaintenanceRequest> =
    createCursorSource<MaintenanceRequest>({
      endpoint: '/maintenance-requests',
      baseParams: { status: 'pending_review', sort: 'created_at:asc' },
    })

  const myRequestsSource: ServerDataOptions<MaintenanceRequest> =
    createCursorSource<MaintenanceRequest>({
      endpoint: '/maintenance-requests',
      baseParams: { created_by: auth.user?.id ?? 0, sort: 'created_at:desc' },
    })

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
      // The caller refreshes its My Requests <Table> via tableRef.refresh().
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
    allMrSource,
    awaitingSource,
    myRequestsSource,
    assetSearch,
    createOpen, confirmCreateOpen, createLoading, createPriority, createDescription,
    attachFiles, addFiles, removeFile,
    canCreate,
    requestCreate, doCreate, closeCreate,
  }
}
