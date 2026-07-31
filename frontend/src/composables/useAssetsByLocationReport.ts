import { computed, ref } from 'vue'
import api from '@/lib/api'
import type { AssetKind, AssetsByLocationReport } from '@/types'

/** Filters accepted by GET /api/reports/assets-by-location (R-2). */
export interface AssetsByLocationFilters {
  /** ERP FA subclass code — the "Asset Class" filter (replaces the legacy `category`). */
  fa_subclass_code?: string
  asset_kind?: AssetKind | ''
  operational_status?: string | ''
  include_inactive?: boolean
}

/**
 * R-2 Asset Distribution by Location — bounded `{ summary, items }` shape.
 * One row per location plus an "Unassigned" bucket for assets with no location.
 */
export function useAssetsByLocationReport() {
  const data = ref<AssetsByLocationReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(filters: AssetsByLocationFilters = {}): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const query: Record<string, string | boolean> = {}
      if (filters.fa_subclass_code) {
        query.fa_subclass_code = filters.fa_subclass_code
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
      data.value = await api.get<AssetsByLocationReport>('/reports/assets-by-location', query)
    } catch {
      error.value = 'Failed to load the assets-by-location report.'
      data.value = null
    } finally {
      loading.value = false
    }
  }

  const summary = computed(() => data.value?.summary ?? null)
  const rows = computed(() => data.value?.items ?? [])

  return { data, loading, error, load, summary, rows }
}
