import { ref } from 'vue'
import api from '@/lib/api'
import type { DashboardKpiResponse, RelocatedAssetItem } from '@/types'

/**
 * "Recently moved" assets for the Find & Move view.
 *
 * Currently sourced from the dashboard KPI feed
 * (`GET /dashboard/kpis` → `recently_relocated_assets`), which the backend caps
 * at 5 within a rolling 90-day window. A dedicated "last 10 relocations"
 * endpoint is a pending backend item — once it lands, point `loadRecentMoves`
 * at it; the view needs no change.
 */
export function useRecentMoves() {
  const recentMoves = ref<RelocatedAssetItem[]>([])
  const recentLoading = ref(true)

  async function loadRecentMoves() {
    recentLoading.value = true
    try {
      const res = await api.get<DashboardKpiResponse>('/dashboard/kpis')
      recentMoves.value = res.recently_relocated_assets ?? []
    } catch {
      recentMoves.value = []
    } finally {
      recentLoading.value = false
    }
  }

  return { recentMoves, recentLoading, loadRecentMoves }
}
