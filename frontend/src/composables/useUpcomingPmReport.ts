import { computed, ref } from 'vue'
import api from '@/lib/api'
import type { UpcomingPmReport } from '@/types'

/** Filters accepted by GET /api/reports/upcoming-pm (R-1). */
export interface UpcomingPmFilters {
  /** Forward horizon in days (default 30, 1..365). */
  days?: number | string
  location_id?: string | number
  pm_rule_id?: string | number
}

/**
 * R-1 Upcoming PM Schedule — bounded `{ summary, items }`. Date-triggered PMs
 * (and the date branch of date_or_reading) due within the horizon on ENROLLED
 * assets. Never-triggered (due-now) and already-overdue assignments are excluded.
 */
export function useUpcomingPmReport() {
  const data = ref<UpcomingPmReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(filters: UpcomingPmFilters = {}): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const query: Record<string, string | number> = {}
      if (filters.days) {
        query.days = filters.days
      }
      if (filters.location_id) {
        query.location_id = filters.location_id
      }
      if (filters.pm_rule_id) {
        query.pm_rule_id = filters.pm_rule_id
      }
      data.value = await api.get<UpcomingPmReport>('/reports/upcoming-pm', query)
    } catch {
      error.value = 'Failed to load the upcoming PM report.'
      data.value = null
    } finally {
      loading.value = false
    }
  }

  const summary = computed(() => data.value?.summary ?? null)
  const rows = computed(() => data.value?.items ?? [])

  /** Trigger-type breakdown → [{ key: PmTriggerType, count }]. */
  const triggerBreakdown = computed(() =>
    Object.entries(summary.value?.by_trigger_type ?? {}).map(([key, count]) => ({ key, count })),
  )

  /** Due-week breakdown → chronological [{ week: "2026-W28", count }]. */
  const dueWeekBreakdown = computed(() =>
    Object.entries(summary.value?.by_due_week ?? {})
      .map(([week, count]) => ({ week, count }))
      .sort((a, b) => a.week.localeCompare(b.week)),
  )

  return { data, loading, error, load, summary, rows, triggerBreakdown, dueWeekBreakdown }
}
