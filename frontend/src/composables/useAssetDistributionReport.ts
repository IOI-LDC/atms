import { computed, ref } from 'vue'
import api from '@/lib/api'
import type { AssetKind, AssetDistributionGroupBy, AssetDistributionReport } from '@/types'

/** Filters accepted by GET /api/reports/asset-distribution (R-2). */
export interface AssetDistributionFilters {
  /**
   * Dimensions to cut by, in column order. Pass several to get one row per
   * distinct combination. Omitted means `['location']`, the backend default.
   */
  group_by?: AssetDistributionGroupBy[]
  /** ATMS-owned Maintenance Category id. FA Subclass is ERP-owned and not offered. */
  maintenance_category_id?: string | number
  asset_kind?: AssetKind | ''
  operational_status?: string | ''
  include_inactive?: boolean
}

/** Human label for a grouping dimension. */
export function dimensionLabel(dimension: AssetDistributionGroupBy): string {
  switch (dimension) {
    case 'maintenance_category':
      return 'Maintenance category'
    case 'size':
      return 'Size'
    default:
      return 'Location'
  }
}

/**
 * R-2 Asset Distribution — bounded `{ group_by, summary, items }` shape.
 *
 * Cuts by any combination of location, maintenance category, and size. One
 * dimension gives a clean per-location (or per-category, per-size) summary;
 * several give one row per distinct combination, which is what makes the export
 * a pivot-ready table. Assets missing a value fall into a named catch-all bucket
 * rather than being dropped, so row counts always reconcile with the total.
 */
export function useAssetDistributionReport() {
  const data = ref<AssetDistributionReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(filters: AssetDistributionFilters = {}): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const query: Record<string, string | number | boolean | string[]> = {}
      if (filters.group_by?.length) {
        query.group_by = filters.group_by
      }
      if (filters.maintenance_category_id) {
        query.maintenance_category_id = filters.maintenance_category_id
      }
      if (filters.asset_kind) {
        query.asset_kind = filters.asset_kind
      }
      if (filters.operational_status) {
        query.operational_status = filters.operational_status
      }
      if (filters.include_inactive) {
        query.include_inactive = true
      }
      data.value = await api.get<AssetDistributionReport>('/reports/asset-distribution', query)
    } catch {
      error.value = 'Failed to load the asset distribution report.'
      data.value = null
    } finally {
      loading.value = false
    }
  }

  const summary = computed(() => data.value?.summary ?? null)
  const rows = computed(() => data.value?.items ?? [])

  /** One header per grouped dimension, in the order the report returned them. */
  const groupHeaders = computed(() => (data.value?.group_by ?? ['location']).map(dimensionLabel))

  /** Label for the summary tile — plural when several dimensions are combined. */
  const groupsLabel = computed(() =>
    (data.value?.group_by ?? []).length > 1
      ? 'Combinations'
      : `${groupHeaders.value[0] ?? 'Group'}s`,
  )

  return { data, loading, error, load, summary, rows, groupHeaders, groupsLabel }
}
