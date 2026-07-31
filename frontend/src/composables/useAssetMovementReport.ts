import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { AssetMovementItem, AssetMovementReportPage } from '@/types'

/** Filters accepted by GET /api/reports/asset-movement (R-18). */
export interface AssetMovementFilters {
  from?: string
  to?: string
  asset_id?: string | number
  from_location_id?: string | number
  to_location_id?: string | number
}

const PER_PAGE = 25

/**
 * R-18 Asset Movement Log — cursor-paginated `{ summary, data, links, meta }`.
 * Read-only relocation history (AM-owned data), from → to per movement.
 */
export function useAssetMovementReport() {
  const rows = ref<AssetMovementItem[]>([])
  const summary = ref<AssetMovementReportPage['summary'] | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  let activeFilters: AssetMovementFilters = {}

  function buildQuery(cursor: string | null): Record<string, string | number> {
    const query: Record<string, string | number> = { per_page: PER_PAGE }
    if (activeFilters.from) {
      query.from = activeFilters.from
    }
    if (activeFilters.to) {
      query.to = activeFilters.to
    }
    if (activeFilters.asset_id) {
      query.asset_id = activeFilters.asset_id
    }
    if (activeFilters.from_location_id) {
      query.from_location_id = activeFilters.from_location_id
    }
    if (activeFilters.to_location_id) {
      query.to_location_id = activeFilters.to_location_id
    }
    if (cursor) {
      query.cursor = cursor
    }
    return query
  }

  async function load(filters: AssetMovementFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<AssetMovementReportPage>('/reports/asset-movement', buildQuery(null))
      rows.value = res.data ?? []
      summary.value = res.summary ?? null
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      summary.value = null
      nextCursor.value = null
      error.value = 'Failed to load the asset movement report.'
    } finally {
      loading.value = false
    }
  }

  async function loadMore(): Promise<void> {
    if (!nextCursor.value || loadingMore.value) {
      return
    }
    loadingMore.value = true
    error.value = null
    try {
      const res = await api.get<AssetMovementReportPage>(
        '/reports/asset-movement',
        buildQuery(nextCursor.value),
      )
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      error.value = 'Failed to load more movements.'
    } finally {
      loadingMore.value = false
    }
  }

  return { rows, summary, loading, loadingMore, error, hasMore, load, loadMore }
}
