import { ref } from 'vue'
import type { Asset } from '@/types'

/**
 * Orchestrates the "move an asset to a new location" interaction: which asset is
 * selected, whether the move sheet is open, and refreshing the caller's list
 * after a successful save. The actual persistence lives in `UpdateLocationSheet`
 * (`POST /assets/{id}/location`).
 *
 * Seam for Asset Assembly (Phase 2): when a parent moves, its children should
 * move with it. This composable is the intended home for that cascade set — a
 * future `childIds` ref selected in the move sheet — so the move stays modelled
 * as "primary asset + optional cascade set" without reshaping the view.
 *
 * @param onRefresh Called after a successful save so the caller can reload its
 *                  visible list (e.g. re-run the search).
 */
export function useAssetMove(onRefresh: () => void) {
  const selectedAsset = ref<Asset | null>(null)
  const sheetOpen = ref(false)

  function openMove(asset: Asset) {
    selectedAsset.value = asset
    sheetOpen.value = true
  }

  function closeMove() {
    sheetOpen.value = false
    selectedAsset.value = null
  }

  function onSaved() {
    onRefresh()
  }

  return { selectedAsset, sheetOpen, openMove, closeMove, onSaved }
}
