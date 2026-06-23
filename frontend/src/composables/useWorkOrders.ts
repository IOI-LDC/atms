import { ref } from 'vue'
import type { Ref } from 'vue'
import { useAuthStore } from '@/stores/auth.store'
import { fetchList } from '@/lib/dataTableSource'
import type { WorkOrder } from '@/types'

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

  return { rows, loading, load }
}

export function useWorkOrders() {
  const auth = useAuthStore()
  const me = auth.user?.id ?? 0

  // Each tab fetches its slice once (client mode); the table sorts/filters/
  // searches in memory. "Active" (open OR in_progress) is split into Open +
  // In Progress because the backend accepts only a single exact-match status.
  const myWorkOrders = useFetchList<WorkOrder>('/work-orders', { assigned_to: me, sort: 'created_at:desc' })
  const all         = useFetchList<WorkOrder>('/work-orders', { sort: 'created_at:desc' })
  const open        = useFetchList<WorkOrder>('/work-orders', { status: 'open', sort: 'created_at:desc' })
  const inProgress  = useFetchList<WorkOrder>('/work-orders', { status: 'in_progress', sort: 'created_at:desc' })
  const completed   = useFetchList<WorkOrder>('/work-orders', { status: 'completed', sort: 'created_at:desc' })
  const closed      = useFetchList<WorkOrder>('/work-orders', { status: 'closed', sort: 'created_at:desc' })

  return { myWorkOrders, all, open, inProgress, completed, closed }
}
