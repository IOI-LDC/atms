import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { TechnicianWorkloadReportPage, TechnicianWorkloadRow } from '@/types'

/** Filters accepted by GET /api/reports/technician-workload (R-15). */
export interface TechnicianWorkloadFilters {
  from?: string
  to?: string
  technician_id?: string | number
}

const PER_PAGE = 25

/**
 * R-15 Workload by Technician — cursor (`{ summary, data, meta }`, no links).
 * Operational workload per technician; summary carries fleet-wide totals.
 */
export function useTechnicianWorkloadReport() {
  const rows = ref<TechnicianWorkloadRow[]>([])
  const summary = ref<TechnicianWorkloadReportPage['summary'] | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  let activeFilters: TechnicianWorkloadFilters = {}

  function buildQuery(cursor: string | null): Record<string, string | number> {
    const query: Record<string, string | number> = { per_page: PER_PAGE }
    if (activeFilters.from) {
      query.from = activeFilters.from
    }
    if (activeFilters.to) {
      query.to = activeFilters.to
    }
    if (activeFilters.technician_id) {
      query.technician_id = activeFilters.technician_id
    }
    if (cursor) {
      query.cursor = cursor
    }
    return query
  }

  async function load(filters: TechnicianWorkloadFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<TechnicianWorkloadReportPage>(
        '/reports/technician-workload',
        buildQuery(null),
      )
      rows.value = res.data ?? []
      summary.value = res.summary ?? null
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      summary.value = null
      nextCursor.value = null
      error.value = 'Failed to load the technician workload report.'
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
      const res = await api.get<TechnicianWorkloadReportPage>(
        '/reports/technician-workload',
        buildQuery(nextCursor.value),
      )
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      error.value = 'Failed to load more technicians.'
    } finally {
      loadingMore.value = false
    }
  }

  return { rows, summary, loading, loadingMore, error, hasMore, load, loadMore }
}
