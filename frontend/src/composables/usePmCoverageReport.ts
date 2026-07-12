import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { Asset, AssetKind, PmCoverageReportPage } from '@/types'

/** Filters accepted by GET /api/reports/pm-coverage (R-9). */
export interface PmCoverageFilters {
  location_id?: string | number
  asset_kind?: AssetKind | ''
}

const PER_PAGE = 25

/**
 * R-9 PM Coverage / Gaps — cursor-paginated `{ summary, data, links, meta }`.
 * Data is the list of active assets with NO active PM assignment (role-gated
 * AssetResource); summary carries the coverage percentage.
 */
export function usePmCoverageReport() {
  const rows = ref<Asset[]>([])
  const summary = ref<PmCoverageReportPage['summary'] | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  let activeFilters: PmCoverageFilters = {}

  function buildQuery(cursor: string | null): Record<string, string | number> {
    const query: Record<string, string | number> = { per_page: PER_PAGE }
    if (activeFilters.location_id) {
      query.location_id = activeFilters.location_id
    }
    if (activeFilters.asset_kind) {
      query.asset_kind = activeFilters.asset_kind
    }
    if (cursor) {
      query.cursor = cursor
    }
    return query
  }

  async function load(filters: PmCoverageFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<PmCoverageReportPage>('/reports/pm-coverage', buildQuery(null))
      rows.value = res.data ?? []
      summary.value = res.summary ?? null
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      summary.value = null
      nextCursor.value = null
      error.value = 'Failed to load the PM coverage report.'
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
      const res = await api.get<PmCoverageReportPage>('/reports/pm-coverage', buildQuery(nextCursor.value))
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      error.value = 'Failed to load more assets.'
    } finally {
      loadingMore.value = false
    }
  }

  return { rows, summary, loading, loadingMore, error, hasMore, load, loadMore }
}
