import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { AssetStatusReportItem, AssetStatusReportPage } from '@/types'

/** Filters accepted by GET /api/reports/asset-status (R-1). */
export interface AssetStatusFilters {
  location_id?: string | number
  operational_status?: string
  /** `asset_conditions` value — the cause axis, orthogonal to operational_status. */
  condition_status?: string
  asset_kind?: string
  maintenance_category_id?: string | number
  booked?: boolean
  /** Inclusive date bounds applied to `date_field`. */
  from?: string
  to?: string
  /** Which timestamp the range filters. Defaults to `updated_at` server-side. */
  date_field?: 'created_at' | 'updated_at'
}

const PER_PAGE = 50

/**
 * R-1 Assets Status Report — cursor-paginated `{ summary, data, links, meta }`.
 * One page at a time, appended via loadMore(); never `fetchList`, since the
 * register can grow without bound.
 *
 * The date range filters created/updated timestamps, NOT status history — past
 * operational status cannot be reconstructed. The view states this next to the
 * date controls so the output is not misread.
 */
export function useAssetStatusReport() {
  const rows = ref<AssetStatusReportItem[]>([])
  const summary = ref<AssetStatusReportPage['summary'] | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  let activeFilters: AssetStatusFilters = {}

  function buildQuery(cursor: string | null): Record<string, string | number | boolean> {
    const query: Record<string, string | number | boolean> = { per_page: PER_PAGE }

    if (activeFilters.location_id) query.location_id = activeFilters.location_id
    if (activeFilters.operational_status)
      query.operational_status = activeFilters.operational_status
    if (activeFilters.condition_status) query.condition_status = activeFilters.condition_status
    if (activeFilters.asset_kind) query.asset_kind = activeFilters.asset_kind
    if (activeFilters.maintenance_category_id)
      query.maintenance_category_id = activeFilters.maintenance_category_id
    if (activeFilters.booked !== undefined) query.booked = activeFilters.booked
    if (activeFilters.from) query.from = activeFilters.from
    if (activeFilters.to) query.to = activeFilters.to
    if (activeFilters.date_field) query.date_field = activeFilters.date_field
    if (cursor) query.cursor = cursor

    return query
  }

  async function load(filters: AssetStatusFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<AssetStatusReportPage>('/reports/asset-status', buildQuery(null))
      rows.value = res.data ?? []
      summary.value = res.summary ?? null
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      summary.value = null
      nextCursor.value = null
      error.value = 'Failed to load the assets status report.'
    } finally {
      loading.value = false
    }
  }

  async function loadMore(): Promise<void> {
    if (!nextCursor.value || loadingMore.value) return

    loadingMore.value = true
    error.value = null
    try {
      const res = await api.get<AssetStatusReportPage>(
        '/reports/asset-status',
        buildQuery(nextCursor.value),
      )
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      error.value = 'Failed to load more rows.'
    } finally {
      loadingMore.value = false
    }
  }

  return { rows, summary, loading, loadingMore, error, hasMore, load, loadMore, activeFilters }
}
