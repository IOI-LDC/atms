import { ref, type Ref } from 'vue'
import api from '@/lib/api'
import type { Asset, CursorPage } from '@/types'

/**
 * Debounced asset search scoped to an optional location, for the Logistics
 * "Find & Move" locator combobox. Like `useAssetSearch`, but adds a
 * `location_id` filter and returns the full Asset (tag, serial, kind, current
 * location) so the dropdown can show rich rows. The backend supports both
 * `search` and `location_id` on `GET /assets` (AssetIndexQuery).
 *
 * @param locationId Reactive location filter — `null` searches all locations.
 */
export function useAssetLocator(locationId: Ref<number | null>) {
  const query = ref('')
  const results = ref<Asset[]>([])
  const busy = ref(false)
  let timer = 0

  async function fetchAssets() {
    busy.value = true
    try {
      const params: Record<string, string | number | boolean> = { is_active: true, per_page: 10 }
      if (query.value.trim()) {
        params.search = query.value
      }
      if (locationId.value) {
        params.location_id = locationId.value
      }
      const res = await api.get<CursorPage<Asset>>('/assets', params)
      results.value = res.data
    } catch {
      results.value = []
    } finally {
      busy.value = false
    }
  }

  /** Load the current (unfiltered-by-text) page — call when the popover opens. */
  function loadInitial() {
    query.value = ''
    clearTimeout(timer)
    fetchAssets()
  }

  /** Debounced search driven by the current query + location scope. */
  function search() {
    clearTimeout(timer)
    timer = window.setTimeout(fetchAssets, 250)
  }

  function reset() {
    clearTimeout(timer)
    query.value = ''
    results.value = []
  }

  return { query, results, busy, search, loadInitial, reset }
}
