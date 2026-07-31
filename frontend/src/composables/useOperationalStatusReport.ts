import { computed, ref } from 'vue'
import api from '@/lib/api'
import type { AssetKind, OperationalStatusDistributionReport } from '@/types'

/** Filters accepted by GET /api/reports/asset-status-distribution (R-10A). */
export interface OperationalStatusReportFilters {
  /** Restrict to one asset kind; empty = all kinds. */
  asset_kind?: AssetKind | ''
  /** Include soft-deactivated assets (is_active = false). Default excludes them. */
  include_inactive?: boolean
}

/**
 * R-10A Operational Status Distribution — bounded `{ summary, items }` shape.
 * Always returns all four operational statuses (missing ones filled with 0).
 */
export function useOperationalStatusReport() {
  const data = ref<OperationalStatusDistributionReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(filters: OperationalStatusReportFilters = {}): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const query: Record<string, string | boolean> = {}
      if (filters.asset_kind) {
        query.asset_kind = filters.asset_kind
      }
      if (filters.include_inactive) {
        query.include_inactive = true
      }
      data.value = await api.get<OperationalStatusDistributionReport>(
        '/reports/asset-status-distribution',
        query,
      )
    } catch {
      error.value = 'Failed to load the operational status distribution report.'
      data.value = null
    } finally {
      loading.value = false
    }
  }

  const total = computed(() => data.value?.summary.total ?? 0)

  /** Distribution rows with each status's share of the total (0 when empty). */
  const rows = computed(() =>
    (data.value?.items ?? []).map((item) => ({
      status: item.status,
      count: item.count,
      percentage: total.value > 0 ? Math.round((item.count / total.value) * 1000) / 10 : 0,
    })),
  )

  return { data, loading, error, load, total, rows }
}
