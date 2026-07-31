import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { AgingBucket, OverduePmItem, OverduePmReportPage } from '@/types'

/** Filters accepted by GET /api/reports/overdue-pm (R-8). */
export interface OverduePmFilters {
  location_id?: string | number
  pm_rule_id?: string | number
  priority?: string
  /** Narrows only the paginated rows; the summary stays facet-context (all buckets). */
  bucket?: AgingBucket
}

const PER_PAGE = 25

/**
 * R-8 Overdue PM — cursor-paginated `{ summary, data, links, meta }`. One page held
 * at a time, appended via loadMore() (audit-log pattern, never fetchList). The
 * summary is facet context: all four buckets over the scoped set, set once on load
 * and left untouched by loadMore().
 */
export function useOverduePmReport() {
  const rows = ref<OverduePmItem[]>([])
  const summary = ref<OverduePmReportPage['summary'] | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  let activeFilters: OverduePmFilters = {}

  function buildQuery(cursor: string | null): Record<string, string | number> {
    const query: Record<string, string | number> = { per_page: PER_PAGE }
    if (activeFilters.location_id) {
      query.location_id = activeFilters.location_id
    }
    if (activeFilters.pm_rule_id) {
      query.pm_rule_id = activeFilters.pm_rule_id
    }
    if (activeFilters.priority) {
      query.priority = activeFilters.priority
    }
    if (activeFilters.bucket) {
      query.bucket = activeFilters.bucket
    }
    if (cursor) {
      query.cursor = cursor
    }
    return query
  }

  async function load(filters: OverduePmFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<OverduePmReportPage>('/reports/overdue-pm', buildQuery(null))
      rows.value = res.data ?? []
      summary.value = res.summary ?? null
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      summary.value = null
      nextCursor.value = null
      error.value = 'Failed to load the overdue PM report.'
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
      const res = await api.get<OverduePmReportPage>(
        '/reports/overdue-pm',
        buildQuery(nextCursor.value),
      )
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
      // summary is facet context — leave the page-one value in place.
    } catch {
      error.value = 'Failed to load more overdue PMs.'
    } finally {
      loadingMore.value = false
    }
  }

  return { rows, summary, loading, loadingMore, error, hasMore, load, loadMore }
}
