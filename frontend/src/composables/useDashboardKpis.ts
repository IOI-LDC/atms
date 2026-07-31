import { ref, computed, onMounted } from 'vue'
import api from '@/lib/api'
import type { DashboardKpiResponse } from '@/types'
import { fmtDate } from '@/lib/displayHelpers'

/**
 * One segment of a stacked bar, sized as a share of the whole population.
 * `key` selects the segment's colour in the SegmentedBar primitive.
 */
export interface UtilisationSegment {
  key: string
  label: string
  count: number
  width: number
}

/** One row of the Programme Readiness band. */
export interface ReadinessRow {
  key: string
  label: string
  covered: number
  percentage: number | null
  total: number
}

/** One labelled count in the Asset status card. `tone` drives the status dot. */
export interface StatusRow {
  key: string
  label: string
  count: number
  tone: 'active' | 'warning' | 'critical' | 'muted' | 'info'
}

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

  const utilisation = computed(() => kpis.value?.utilisation ?? null)

  /**
   * Bar segments for the utilisation band, as percentages of the whole asset
   * population. Deliberately includes `unlocated` so the data gap is visible in
   * the bar even though it is excluded from the utilisation percentage itself.
   */
  const utilisationSegments = computed<UtilisationSegment[]>(() => {
    const u = utilisation.value
    if (!u || u.total === 0) return []

    const pct = (n: number) => (n / u.total) * 100

    const segments: UtilisationSegment[] = [
      { key: 'deployed', label: 'Deployed', count: u.by_bucket.deployed, width: pct(u.by_bucket.deployed) },
      { key: 'idle', label: 'Idle', count: u.by_bucket.idle, width: pct(u.by_bucket.idle) },
      {
        key: 'maintenance',
        label: 'Maintenance',
        count: u.by_bucket.maintenance,
        width: pct(u.by_bucket.maintenance),
      },
      { key: 'unlocated', label: 'No location', count: u.unlocated, width: pct(u.unlocated) },
      {
        key: 'unclassified',
        label: 'Unclassified location',
        count: u.unclassified,
        width: pct(u.unclassified),
      },
    ]

    return segments.filter((s) => s.count > 0)
  })

  /** "1 of 4 eligible assets deployed" — states the denominator explicitly. */
  const utilisationBasis = computed(() => {
    const u = utilisation.value
    if (!u) return ''
    if (u.eligible === 0) return 'No assets are eligible for deployment yet'
    return `${u.deployed_eligible} of ${u.eligible} eligible assets deployed`
  })

  const readinessMetrics = computed<ReadinessRow[]>(() => {
    const r = kpis.value?.readiness
    if (!r) return []

    return [
      { key: 'pm_coverage', label: 'PM coverage', ...r.pm_coverage, total: r.total },
      { key: 'location_recorded', label: 'Location recorded', ...r.location_recorded, total: r.total },
      { key: 'baseline_reading', label: 'Baseline reading', ...r.baseline_reading, total: r.total },
    ]
  })

  /**
   * Operational status, one row per state, always all four — a state showing 0
   * is information, not noise, so these are never filtered out.
   *
   * Withdrawal and its sub-statuses (disposed, scrapped, lost in hole, …) are
   * owned by the ERP and are deliberately not surfaced here.
   */
  const operationalStatusRows = computed<StatusRow[]>(() => {
    const h = kpis.value?.asset_health
    if (!h) return []

    return [
      { key: 'active', label: 'Active', count: h.by_status.active, tone: 'active' },
      {
        key: 'under_maintenance',
        label: 'Under Maintenance',
        count: h.by_status.under_maintenance,
        tone: 'warning',
      },
      { key: 'down', label: 'Down', count: h.by_status.down, tone: 'critical' },
      { key: 'inactive', label: 'Inactive', count: h.by_status.inactive, tone: 'muted' },
    ]
  })

  /**
   * Booking sits below a separator because it is a different axis, not a fifth
   * operational state — an asset can be Booked and Under Maintenance at once, so
   * this count deliberately does not sum with the rows above it.
   */
  const bookedRow = computed<StatusRow | null>(() => {
    const h = kpis.value?.asset_health
    if (!h) return null

    return { key: 'booked', label: 'Booked', count: h.by_booking.booked, tone: 'info' }
  })

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
    utilisation,
    utilisationSegments,
    utilisationBasis,
    readinessMetrics,
    operationalStatusRows,
    bookedRow,
  }
}
