import { ref, computed, onMounted } from 'vue'
import api from '@/lib/api'
import type { DashboardKpiResponse } from '@/types'
import { fmtDate } from '@/lib/displayHelpers'

/**
 * Reliability + process KPIs and the recently-relocated-assets feed from
 * `GET /api/dashboard/kpis`. Full payload to every role over a rolling 90-day
 * window. Mirrors useDashboard's ref + onMounted + reload shape so the view can
 * fire both dashboard calls in parallel and refresh them together.
 */
export function useDashboardKpis() {
  const data = ref<DashboardKpiResponse | null>(null)
  const loading = ref(true)
  const error = ref<string | null>(null)

  async function reload() {
    loading.value = true
    error.value = null
    try {
      data.value = await api.get<DashboardKpiResponse>('/dashboard/kpis')
    } catch {
      error.value = 'Failed to load KPI metrics.'
    } finally {
      loading.value = false
    }
  }

  onMounted(reload)

  const kpis = computed(() => data.value?.kpis ?? null)
  const relocated = computed(() => data.value?.recently_relocated_assets ?? [])

  // Window scope caption — the KPIs and relocated feed are a fixed 90-day window.
  const windowDays = computed(() => data.value?.window.days ?? 90)
  const windowLabel = computed(() => (data.value ? `Last ${windowDays.value} days` : ''))
  const windowRange = computed(() => {
    const w = data.value?.window
    return w ? `${fmtDate(w.from)} → ${fmtDate(w.to)}` : ''
  })

  return {
    data,
    loading,
    error,
    reload,
    kpis,
    relocated,
    windowDays,
    windowLabel,
    windowRange,
  }
}
