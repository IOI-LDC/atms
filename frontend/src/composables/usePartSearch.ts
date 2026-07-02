import { ref } from 'vue'
import api from '@/lib/api'
import type { Part, CursorPage } from '@/types'

/**
 * Debounced backend part search. A pure "search engine": owns only the query
 * string, the result set, and a busy flag. Selection lives in the caller
 * (PartCombobox binds it via v-model), so this stays free of any form/
 * persistence concerns. Mirrors useAssetSearch.ts.
 */
export function usePartSearch() {
  const query   = ref('')
  const results = ref<Part[]>([])
  const busy    = ref(false)
  let   timer   = 0

  /** Fetch the current page (uses `search` when set, else an unfiltered page). */
  async function fetchParts() {
    busy.value = true
    try {
      const params = query.value.trim()
        ? { search: query.value, per_page: 10 }
        : { per_page: 10, sort: 'name:asc' }
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
