import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { PartsConsumptionItem, PartsConsumptionReportPage } from '@/types'

/** Filters accepted by GET /api/reports/parts-consumption (R-17). */
export interface PartsConsumptionFilters {
  from?: string
  to?: string
  part_id?: string | number
  asset_id?: string | number
  fa_subclass_code?: string
}

const PER_PAGE = 25

/**
 * R-17 Parts Consumption — cursor-paginated `{ summary, data, links, meta }`.
 * Quantities from completed/closed WOs, aggregated by part + FA subclass code.
 */
export function usePartsConsumptionReport() {
  const rows = ref<PartsConsumptionItem[]>([])
  const summary = ref<PartsConsumptionReportPage['summary'] | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  let activeFilters: PartsConsumptionFilters = {}

  function buildQuery(cursor: string | null): Record<string, string | number> {
    const query: Record<string, string | number> = { per_page: PER_PAGE }
    if (activeFilters.from) {
      query.from = activeFilters.from
    }
    if (activeFilters.to) {
      query.to = activeFilters.to
    }
    if (activeFilters.part_id) {
      query.part_id = activeFilters.part_id
    }
    if (activeFilters.asset_id) {
      query.asset_id = activeFilters.asset_id
    }
    if (activeFilters.fa_subclass_code) {
      query.fa_subclass_code = activeFilters.fa_subclass_code
    }
    if (cursor) {
      query.cursor = cursor
    }
    return query
  }

  async function load(filters: PartsConsumptionFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<PartsConsumptionReportPage>('/reports/parts-consumption', buildQuery(null))
      rows.value = res.data ?? []
      summary.value = res.summary ?? null
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      summary.value = null
      nextCursor.value = null
      error.value = 'Failed to load the parts consumption report.'
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
      const res = await api.get<PartsConsumptionReportPage>(
        '/reports/parts-consumption',
        buildQuery(nextCursor.value),
      )
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      error.value = 'Failed to load more parts.'
    } finally {
      loadingMore.value = false
    }
  }

  return { rows, summary, loading, loadingMore, error, hasMore, load, loadMore }
}
