import { ref } from 'vue'
import type { Ref } from 'vue'
import { fetchList } from '@/lib/dataTableSource'
import type { Part } from '@/types'

/**
 * A client-mode list slice: rows + loading + a one-shot (cacheable) loader.
 * Matches the pattern established in useAssets.ts / useWorkOrders.ts.
 */
function useFetchList<T>(endpoint: string, baseParams: Record<string, string | number>) {
  const rows    = ref<T[]>([]) as Ref<T[]>
  const loading = ref(false)
  const loaded  = ref(false)

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

/**
 * State and actions for the Parts Management list page.
 *
 * Backend contract (see docs/atms/04-technical/BACKEND_API_REFERENCE.md):
 *  GET /api/parts -> cursor-paginated PartResource list. Non-Admin/Manager
 *  callers only receive is_active=true rows (enforced server-side).
 */
export function useParts() {
  // Single slice — full catalogue sorted by name. Client-mode: fetched once;
  // AppDataTable searches/filters/sorts in memory (55 seed rows today).
  const all = useFetchList<Part>('/parts', { sort: 'name:asc' })

  return { all }
}
