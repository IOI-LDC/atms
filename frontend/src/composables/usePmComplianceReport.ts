import { computed, ref } from 'vue'
import api from '@/lib/api'
import type { PmComplianceGroupBy, PmComplianceReport } from '@/types'

/** Filters accepted by GET /api/reports/pm-compliance (R-7). */
export interface PmComplianceFilters {
  from?: string // yyyy-MM-dd
  to?: string // yyyy-MM-dd
  group_by?: PmComplianceGroupBy
  location_id?: string | number
  pm_rule_id?: string | number
}

/** Default backward window: last 90 days (matches the dashboard KPI window). */
function isoDate(d: Date): string {
  return d.toLocaleDateString('en-CA')
}
const now = new Date()
const ninetyAgo = new Date(now)
ninetyAgo.setDate(ninetyAgo.getDate() - 90)
export const PM_COMPLIANCE_DEFAULT_TO = isoDate(now)
export const PM_COMPLIANCE_DEFAULT_FROM = isoDate(ninetyAgo)

/**
 * R-7 PM Compliance — bounded `{ summary, items }` shape. On-time = date-triggered
 * PM whose linked WO closed on or before the trigger date; reading-triggered PMs
 * are excluded (no calendar due date). Grouped by rule, asset, or location.
 */
export function usePmComplianceReport() {
  const data = ref<PmComplianceReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(filters: PmComplianceFilters = {}): Promise<void> {
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
      if (filters.pm_rule_id) {
        query.pm_rule_id = filters.pm_rule_id
      }
      data.value = await api.get<PmComplianceReport>('/reports/pm-compliance', query)
    } catch {
      error.value = 'Failed to load the PM compliance report.'
      data.value = null
    } finally {
      loading.value = false
    }
  }

  const summary = computed(() => data.value?.summary ?? null)
  const rows = computed(() => data.value?.items ?? [])

  return { data, loading, error, load, summary, rows }
}
