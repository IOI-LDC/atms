import { ref, unref, type MaybeRef } from 'vue'
import api from '@/lib/api'
import type { Part, CursorPage } from '@/types'

/**
 * Debounced backend part search. A pure "search engine": owns only the query
 * string, the result set, and a busy flag. Selection lives in the caller
 * (PartCombobox binds it via v-model), so this stays free of any form/
 * persistence concerns. Mirrors useAssetSearch.ts.
 *
 * Pass `compatibleWithAssetId` to restrict results to parts compatible with an
 * asset. Filtering happens on the server — the same rule is re-applied when the
 * part is submitted, so this narrows what is offered rather than enforcing it.
 */
export function usePartSearch(compatibleWithAssetId?: MaybeRef<number | null | undefined>) {
  const query = ref('')
  const results = ref<Part[]>([])
  const busy = ref(false)
  let timer = 0

  /** Fetch the current page (uses `search` when set, else an unfiltered page). */
  async function fetchParts() {
    busy.value = true
    try {
      const assetId = unref(compatibleWithAssetId)
      const params: Record<string, string | number> = query.value.trim()
        ? { search: query.value, per_page: 10 }
        : { per_page: 10, sort: 'name:asc' }

      if (assetId) {
        params.compatible_with_asset_id = assetId
      }

      const res = await api.get<CursorPage<Part>>('/parts', params)
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
    fetchParts()
  }

  /** Debounced search driven by the current query. */
  function search() {
    clearTimeout(timer)
    timer = window.setTimeout(fetchParts, 250)
  }

  /** Clear query + results (called when the popover closes or selection resets). */
  function reset() {
    clearTimeout(timer)
    query.value = ''
    results.value = []
  }

  return { query, results, busy, search, loadInitial, reset }
}
