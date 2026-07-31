import { ref } from 'vue'
import api from '@/lib/api'
import type { Asset, CursorPage } from '@/types'

/**
 * Debounced backend asset search. A pure "search engine": owns only the query
 * string, the result set, and a busy flag. Selection lives in the caller
 * (the AssetCombobox binds it via v-model), so this stays free of any
 * form/persistence concerns.
 *
 * @param options.maintenanceStatus Optional `maintenance_status` filter
 *   (`enrolled` | `withdrawn`) forwarded to `GET /assets` — used by the
 *   work-creating pickers to offer only `enrolled` assets. Omit to search all.
 */
export function useAssetSearch(options: { maintenanceStatus?: string } = {}) {
  const query = ref('')
  const results = ref<Asset[]>([])
  const busy = ref(false)
  let timer = 0

  /** Fetch the current page (uses `search` when set, else an unfiltered page). */
  async function fetchAssets() {
    busy.value = true
    try {
      const params: Record<string, string | number> = { per_page: 10 }
      if (query.value.trim()) {
        params.search = query.value
      }
      if (options.maintenanceStatus) {
        params.maintenance_status = options.maintenanceStatus
      }
      const res = await api.get<CursorPage<Asset>>('/assets', params)
      results.value = res.data
    } catch {
      results.value = []
    } finally {
      busy.value = false
    }
  }

  /** Load a default (unfiltered) page immediately — call when the popover opens. */
  function loadInitial() {
    query.value = ''
    clearTimeout(timer)
    fetchAssets()
  }

  /** Debounced search driven by the current query. */
  function search() {
    clearTimeout(timer)
    timer = window.setTimeout(fetchAssets, 250)
  }

  /** Clear query + results (called when the popover closes or selection resets). */
  function reset() {
    clearTimeout(timer)
    query.value = ''
    results.value = []
  }

  return { query, results, busy, search, loadInitial, reset }
}
