import { computed, ref } from 'vue'
import api from '@/lib/api'
import type { MttrGroupBy, MttrReport } from '@/types'

/** Filters accepted by GET /api/reports/mttr (R-4). */
export interface MttrFilters {
  from?: string
  to?: string
  group_by?: MttrGroupBy
  location_id?: string | number
  fa_subclass_code?: string
  technician_id?: string | number
}

/**
 * R-4 MTTR by dimension — bounded `{ summary, items }`. Corrective closed WOs;
 * MTTR = mean hours between assigned_at and closed_at.
 */
export function useMttrReport() {
  const data = ref<MttrReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(filters: MttrFilters = {}): Promise<void> {
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
      if (filters.technician_id) {
        query.technician_id = filters.technician_id
      }
      data.value = await api.get<MttrReport>('/reports/mttr', query)
    } catch {
      error.value = 'Failed to load the MTTR report.'
      data.value = null
    } finally {
      loading.value = false
    }
  }

  const summary = computed(() => data.value?.summary ?? null)
  const rows = computed(() => data.value?.items ?? [])

  return { data, loading, error, load, summary, rows }
}
