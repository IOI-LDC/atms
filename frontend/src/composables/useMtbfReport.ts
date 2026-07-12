import { computed, ref } from 'vue'
import api from '@/lib/api'
import type { MtbfGroupBy, MtbfReport } from '@/types'

/** Filters accepted by GET /api/reports/mtbf (R-3). */
export interface MtbfFilters {
  from?: string
  to?: string
  group_by?: MtbfGroupBy
  location_id?: string | number
  fa_subclass_code?: string
}

/**
 * R-3 MTBF / Failure Rate by dimension — bounded `{ summary, items }`. Corrective
 * MRs with is_failure=true in the window; MTBF = window_days / failure_count.
 */
export function useMtbfReport() {
  const data = ref<MtbfReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(filters: MtbfFilters = {}): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const query: Record<string, string | number> = {}
      if (filters.from) {
        query.from = filters.from
      }
      if (filters.to) {
        query.to = filters.to
      }
      if (filters.group_by) {
        query.group_by = filters.group_by
      }
      if (filters.location_id) {
        query.location_id = filters.location_id
      }
      if (filters.fa_subclass_code) {
        query.fa_subclass_code = filters.fa_subclass_code
      }
      data.value = await api.get<MtbfReport>('/reports/mtbf', query)
    } catch {
      error.value = 'Failed to load the MTBF report.'
      data.value = null
    } finally {
      loading.value = false
    }
  }

  const summary = computed(() => data.value?.summary ?? null)
  const rows = computed(() => data.value?.items ?? [])

  return { data, loading, error, load, summary, rows }
}
