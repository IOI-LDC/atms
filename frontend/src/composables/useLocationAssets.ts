import { ref } from 'vue'
import { fetchList } from '@/lib/dataTableSource'
import type { Asset } from '@/types'

/**
 * Active assets currently at a given location, for the Find & Move
 * "browse by location" list. Scoped server-side to one location via the
 * `location_id` filter (bounded, unlike loading the whole fleet).
 */
export function useLocationAssets() {
  const locationAssets = ref<Asset[]>([])
  const locationAssetsLoading = ref(false)

  async function loadLocationAssets(locationId: number) {
    locationAssetsLoading.value = true
    try {
      locationAssets.value = await fetchList<Asset>('/assets', {
        is_active: true,
        location_id: locationId,
        sort: 'name:asc',
      })
    } catch {
      locationAssets.value = []
    } finally {
      locationAssetsLoading.value = false
    }
  }

  function clearLocationAssets() {
    locationAssets.value = []
  }

  return { locationAssets, locationAssetsLoading, loadLocationAssets, clearLocationAssets }
}
