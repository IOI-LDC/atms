import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { ThroughputReportPage, ThroughputRow } from '@/types'

/** Filters accepted by GET /api/reports/throughput (R-16). */
export interface ThroughputFilters {
  from?: string
  to?: string
  /** A single MR or WO status; applies only to the source that supports it. */
  status?: string
}

const PER_PAGE = 25

/**
 * R-16 MR / WO Throughput — cursor (`{ summary, data, meta }`, no links). One row
 * per day of MR + WO lifecycle counts; summary carries period totals + avg
 * MR→WO conversion time.
 */
export function useThroughputReport() {
  const rows = ref<ThroughputRow[]>([])
  const summary = ref<ThroughputReportPage['summary'] | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  let activeFilters: ThroughputFilters = {}

  function buildQuery(cursor: string | null): Record<string, string | number> {
    const query: Record<string, string | number> = { per_page: PER_PAGE }
    if (activeFilters.from) {
      query.from = activeFilters.from
    }
    if (activeFilters.to) {
      query.to = activeFilters.to
    }
    if (activeFilters.status) {
      query.status = activeFilters.status
    }
    if (cursor) {
      query.cursor = cursor
    }
    return query
  }

  async function load(filters: ThroughputFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<ThroughputReportPage>('/reports/throughput', buildQuery(null))
      rows.value = res.data ?? []
      summary.value = res.summary ?? null
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      summary.value = null
      nextCursor.value = null
      error.value = 'Failed to load the throughput report.'
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
      const res = await api.get<ThroughputReportPage>('/reports/throughput', buildQuery(nextCursor.value))
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      error.value = 'Failed to load more days.'
    } finally {
      loadingMore.value = false
    }
  }

  return { rows, summary, loading, loadingMore, error, hasMore, load, loadMore }
}
