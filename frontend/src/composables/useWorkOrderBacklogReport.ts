import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { WoBacklogItem, WoBacklogReportPage } from '@/types'

/** Filters accepted by GET /api/reports/wo-backlog (R-14). */
export interface WoBacklogFilters {
  location_id?: string | number
  assigned_to?: string | number
  priority?: string
  status?: 'open' | 'in_progress' | 'both'
}

const PER_PAGE = 25

/**
 * R-14 WO Backlog / Aging — cursor-paginated `{ summary, data, links, meta }`.
 * Open + in-progress work orders by age bucket and priority. One page at a time,
 * appended via loadMore(); the summary (buckets + priority breakdown) is set once
 * on load.
 */
export function useWorkOrderBacklogReport() {
  const rows = ref<WoBacklogItem[]>([])
  const summary = ref<WoBacklogReportPage['summary'] | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  let activeFilters: WoBacklogFilters = {}

  function buildQuery(cursor: string | null): Record<string, string | number> {
    const query: Record<string, string | number> = { per_page: PER_PAGE }
    if (activeFilters.location_id) {
      query.location_id = activeFilters.location_id
    }
    if (activeFilters.assigned_to) {
      query.assigned_to = activeFilters.assigned_to
    }
    if (activeFilters.priority) {
      query.priority = activeFilters.priority
    }
    if (activeFilters.status && activeFilters.status !== 'both') {
      query.status = activeFilters.status
    }
    if (cursor) {
      query.cursor = cursor
    }
    return query
  }

  async function load(filters: WoBacklogFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<WoBacklogReportPage>('/reports/wo-backlog', buildQuery(null))
      rows.value = res.data ?? []
      summary.value = res.summary ?? null
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      summary.value = null
      nextCursor.value = null
      error.value = 'Failed to load the work-order backlog report.'
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
      const res = await api.get<WoBacklogReportPage>(
        '/reports/wo-backlog',
        buildQuery(nextCursor.value),
      )
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      error.value = 'Failed to load more work orders.'
    } finally {
      loadingMore.value = false
    }
  }

  return { rows, summary, loading, loadingMore, error, hasMore, load, loadMore }
}
