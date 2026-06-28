import { ref, computed } from 'vue'
import type { Ref } from 'vue'
import { toast } from 'vue-sonner'
import api, { ApiError } from '@/lib/api'
import { useAuthStore } from '@/stores/auth.store'
import { fetchList } from '@/lib/dataTableSource'
import type { MaintenanceRequest } from '@/types'

/** A client-mode list slice: rows + loading + a one-shot (cacheable) loader. */
function useFetchList<T>(endpoint: string, baseParams: Record<string, string | number>) {
  const rows = ref<T[]>([]) as Ref<T[]>
  const loading = ref(false)
  const loaded = ref(false)

  async function load(force = false) {
    if (loaded.value && !force) return
    loading.value = true
    try {
      rows.value = await fetchList<T>(endpoint, baseParams)
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  return { rows, loading, loaded, load }
}

export function useMaintenanceRequests() {
  const auth = useAuthStore()
  const me = auth.user?.id ?? 0

  // Each tab fetches its slice once (client mode); the table then sorts,
  // filters and searches in memory. baseParams encode the tab's fixed semantics.
  const myRequests = useFetchList<MaintenanceRequest>('/maintenance-requests', {
    created_by: me,
    sort: 'created_at:desc',
  })
  const awaiting = useFetchList<MaintenanceRequest>('/maintenance-requests', {
    status: 'pending_review',
    sort: 'created_at:asc',
  })
  const allRequests = useFetchList<MaintenanceRequest>('/maintenance-requests', {
    sort: 'created_at:desc',
  })

  // ── Create ────────────────────────────────────────────────────────────────────

  // Bound to <AssetCombobox v-model>; selection shape { id, label }.
  const selectedAsset     = ref<{ id: number; label: string } | null>(null)
  const createOpen        = ref(false)
  const confirmCreateOpen = ref(false)
  const createLoading     = ref(false)
  const createPriority    = ref('medium')
  const createDescription = ref('')
  const attachFiles       = ref<File[]>([])

  const canCreate = computed(() => !!auth.user)

  function requestCreate() {
    if (!selectedAsset.value) { toast.error('Please select an asset.'); return }
    confirmCreateOpen.value = true
  }

  function addFiles(files: File[]) {
    attachFiles.value.push(...files)
  }

  function removeFile(i: number) {
    attachFiles.value.splice(i, 1)
  }

  async function doCreate() {
    if (!selectedAsset.value) return
    createLoading.value = true
    try {
      const res = await api.post<{ data: MaintenanceRequest }>('/maintenance-requests/corrective', {
        asset_id:    selectedAsset.value.id,
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
      // Refresh every slice the user has already opened so the new request shows
      // up immediately on whichever tab is active — not just "My Requests".
      // A new corrective MR belongs in My Requests, Pending Approval, and All.
      await Promise.all(
        [myRequests, awaiting, allRequests]
          .filter((slice) => slice.loaded.value)
          .map((slice) => slice.load(true)),
      )
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
    selectedAsset.value     = null
  }

  return {
    myRequests, awaiting, allRequests,
    selectedAsset,
    createOpen, confirmCreateOpen, createLoading, createPriority, createDescription,
    attachFiles, addFiles, removeFile,
    canCreate,
    requestCreate, doCreate, closeCreate,
  }
}
