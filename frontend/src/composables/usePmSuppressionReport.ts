import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { PmSuppressionItem, PmSuppressionReportPage } from '@/types'

/** Filters accepted by GET /api/reports/pm-suppression (R-21). */
export interface PmSuppressionFilters {
  from?: string
  to?: string
  pm_rule_id?: string | number
  asset_id?: string | number
  decision_type?: string
}

const PER_PAGE = 25

/**
 * R-21 PM Suppression Register — cursor-paginated `{ summary, data, links, meta }`.
 * Audit register of suppressed/overridden PM occurrences.
 */
export function usePmSuppressionReport() {
  const rows = ref<PmSuppressionItem[]>([])
  const summary = ref<PmSuppressionReportPage['summary'] | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  let activeFilters: PmSuppressionFilters = {}

  function buildQuery(cursor: string | null): Record<string, string | number> {
    const query: Record<string, string | number> = { per_page: PER_PAGE }
    if (activeFilters.from) {
      query.from = activeFilters.from
    }
    if (activeFilters.to) {
      query.to = activeFilters.to
    }
    if (activeFilters.pm_rule_id) {
      query.pm_rule_id = activeFilters.pm_rule_id
    }
    if (activeFilters.asset_id) {
      query.asset_id = activeFilters.asset_id
    }
    if (activeFilters.decision_type) {
      query.decision_type = activeFilters.decision_type
    }
    if (cursor) {
      query.cursor = cursor
    }
    return query
  }

  async function load(filters: PmSuppressionFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<PmSuppressionReportPage>('/reports/pm-suppression', buildQuery(null))
      rows.value = res.data ?? []
      summary.value = res.summary ?? null
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      summary.value = null
      nextCursor.value = null
      error.value = 'Failed to load the PM suppression report.'
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
      const res = await api.get<PmSuppressionReportPage>(
        '/reports/pm-suppression',
        buildQuery(nextCursor.value),
      )
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      error.value = 'Failed to load more suppressions.'
    } finally {
      loadingMore.value = false
    }
  }

  return { rows, summary, loading, loadingMore, error, hasMore, load, loadMore }
}
