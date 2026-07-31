import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { FormResultRow, FormResultsReportPage } from '@/types'

/** Filters accepted by GET /api/reports/form-results (R-19). */
export interface FormResultsFilters {
  from?: string
  to?: string
  asset_id?: string | number
  fa_subclass_code?: string
  field_uuid?: string
}

const PER_PAGE = 25

/**
 * R-19 Work Order Form Results — cursor-paginated `{ summary, data, links, meta }`.
 * Paginated raw field values; summary carries boolean tallies and the safe
 * same-field+unit numeric comparisons array.
 */
export function useFormResultsReport() {
  const rows = ref<FormResultRow[]>([])
  const summary = ref<FormResultsReportPage['summary'] | null>(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  let activeFilters: FormResultsFilters = {}

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
    if (activeFilters.fa_subclass_code) {
      query.fa_subclass_code = activeFilters.fa_subclass_code
    }
    if (activeFilters.field_uuid) {
      query.field_uuid = activeFilters.field_uuid
    }
    if (cursor) {
      query.cursor = cursor
    }
    return query
  }

  async function load(filters: FormResultsFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<FormResultsReportPage>('/reports/form-results', buildQuery(null))
      rows.value = res.data ?? []
      summary.value = res.summary ?? null
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      summary.value = null
      nextCursor.value = null
      error.value = 'Failed to load the form results report.'
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
      const res = await api.get<FormResultsReportPage>('/reports/form-results', buildQuery(nextCursor.value))
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      error.value = 'Failed to load more results.'
    } finally {
      loadingMore.value = false
    }
  }

  return { rows, summary, loading, loadingMore, error, hasMore, load, loadMore }
}
