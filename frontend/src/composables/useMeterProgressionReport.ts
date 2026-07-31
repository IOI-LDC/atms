import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { MeterProgressionItem, MeterProgressionReportPage } from '@/types'

/** Filters accepted by GET /api/reports/meter-progression (R-20). */
export interface MeterProgressionFilters {
  from?: string
  to?: string
  asset_id?: string | number
  usage_reading_type_id?: string | number
}

const PER_PAGE = 25

/**
 * R-20 Meter Reading Progression — cursor-paginated `{ summary, data, links, meta }`.
 * Confirmed readings with per-reading delta from the previous reading.
 */
export function useMeterProgressionReport() {
  const rows = ref<MeterProgressionItem[]>([])
  const summary = ref<MeterProgressionReportPage['summary'] | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  let activeFilters: MeterProgressionFilters = {}

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
    if (activeFilters.usage_reading_type_id) {
      query.usage_reading_type_id = activeFilters.usage_reading_type_id
    }
    if (cursor) {
      query.cursor = cursor
    }
    return query
  }

  async function load(filters: MeterProgressionFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<MeterProgressionReportPage>('/reports/meter-progression', buildQuery(null))
      rows.value = res.data ?? []
      summary.value = res.summary ?? null
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      summary.value = null
      nextCursor.value = null
      error.value = 'Failed to load the meter progression report.'
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
      const res = await api.get<MeterProgressionReportPage>(
        '/reports/meter-progression',
        buildQuery(nextCursor.value),
      )
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      error.value = 'Failed to load more readings.'
    } finally {
      loadingMore.value = false
    }
  }

  return { rows, summary, loading, loadingMore, error, hasMore, load, loadMore }
}
