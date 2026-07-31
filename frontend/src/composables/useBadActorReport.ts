import { computed, ref } from 'vue'
import api from '@/lib/api'
import type { BadActorReport, MtbfGroupBy } from '@/types'

/** Filters accepted by GET /api/reports/bad-actors (R-6). */
export interface BadActorFilters {
  from?: string
  to?: string
  group_by?: MtbfGroupBy
  location_id?: string | number
  fa_subclass_code?: string
  limit?: string | number
}

/**
 * R-6 Bad-Actor / Breakdown Analysis — bounded `{ summary, items }`. Confirmed
 * failures ranked by count per dimension (no failure taxonomy exists).
 */
export function useBadActorReport() {
  const data = ref<BadActorReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(filters: BadActorFilters = {}): Promise<void> {
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
      if (filters.limit) {
        query.limit = filters.limit
      }
      data.value = await api.get<BadActorReport>('/reports/bad-actors', query)
    } catch {
      error.value = 'Failed to load the bad-actor report.'
      data.value = null
    } finally {
      loading.value = false
    }
  }

  const summary = computed(() => data.value?.summary ?? null)
  const rows = computed(() => data.value?.items ?? [])

  return { data, loading, error, load, summary, rows }
}
