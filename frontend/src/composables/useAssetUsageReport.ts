import { computed, ref } from 'vue'
import api from '@/lib/api'
import type { AssetUsageGroupBy, AssetUsageReport } from '@/types'

/** Filters accepted by GET /api/reports/asset-usage (R-22). */
export interface AssetUsageFilters {
  /** Omitted means the first active reading type, which the backend picks. */
  usage_reading_type_id?: string | number
  group_by?: AssetUsageGroupBy
  from?: string
  to?: string
  location_id?: string | number
  maintenance_category_id?: string | number
  /** Top-N cap on rows. The summary always covers every asset. */
  limit?: number
}

/**
 * R-22 Most-Used Assets — bounded `{ reading_type, summary, items }`.
 *
 * Ranks assets by accumulated usage against one reading type (hours, km, or
 * depth), optionally rolled up by maintenance category or size. Units differ
 * per type, so the report never mixes them: `reading_type.unit` labels every
 * number on the page.
 */
export function useAssetUsageReport() {
  const data = ref<AssetUsageReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(filters: AssetUsageFilters = {}): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const query: Record<string, string | number> = {}
      if (filters.usage_reading_type_id) {
        query.usage_reading_type_id = filters.usage_reading_type_id
      }
      if (filters.group_by) {
        query.group_by = filters.group_by
      }
      if (filters.from) {
        query.from = filters.from
      }
      if (filters.to) {
        query.to = filters.to
      }
      if (filters.location_id) {
        query.location_id = filters.location_id
      }
      if (filters.maintenance_category_id) {
        query.maintenance_category_id = filters.maintenance_category_id
      }
      if (filters.limit) {
        query.limit = filters.limit
      }
      data.value = await api.get<AssetUsageReport>('/reports/asset-usage', query)
    } catch {
      error.value = 'Failed to load the most-used assets report.'
      data.value = null
    } finally {
      loading.value = false
    }
  }

  const summary = computed(() => data.value?.summary ?? null)
  const rows = computed(() => data.value?.items ?? [])
  const readingType = computed(() => data.value?.reading_type ?? null)
  const unit = computed(() => data.value?.reading_type.unit ?? '')

  /** Header for the grouped column, so the table names what it is showing. */
  const groupHeader = computed(() => {
    switch (data.value?.group_by) {
      case 'maintenance_category':
        return 'Maintenance category'
      case 'size':
        return 'Size'
      default:
        return 'Asset'
    }
  })

  /** True while showing one row per asset, which carries extra meter columns. */
  const isPerAsset = computed(() => (data.value?.group_by ?? 'asset') === 'asset')

  return { data, loading, error, load, summary, rows, readingType, unit, groupHeader, isPerAsset }
}
