import { ref } from 'vue'
import type { Ref } from 'vue'
import { fetchList } from '@/lib/dataTableSource'
import api from '@/lib/api'
import type { Asset, Location } from '@/types'

/**
 * A client-mode list slice: rows + loading + a one-shot (cacheable) loader.
 * Matches the pattern established in useWorkOrders.ts.
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
 * State and actions for the Assets Management list page.
 *
 * Backend contract (see docs/04-technical/BACKEND_API_REFERENCE.md):
 *  GET /api/assets                  -> cursor-paginated AssetResource list
 *  GET /api/locations               -> { data: Location[] }  (active-only; Admin/Manager/Logistics)
 */
export function useAssets() {
  // Single slice — all assets sorted by name. Client-mode: full list fetched
  // once; the AppDataTable sorts/filters/searches in memory.
  const all = useFetchList<Asset>('/assets', { sort: 'name:asc' })

  // Locations — fetched on demand, only for Admin/Manager/Logistics (caller
  // must check role before invoking loadLocations).
  const locations        = ref<Location[]>([])
  const locationsLoading = ref(false)

  async function loadLocations() {
    if (locations.value.length > 0) return  // already loaded
    locationsLoading.value = true
    try {
      const res = await api.get<{ data: Location[] }>('/locations')
      locations.value = res.data ?? []
    } catch {
      locations.value = []
    } finally {
      locationsLoading.value = false
    }
  }

  return { all, locations, locationsLoading, loadLocations }
}
