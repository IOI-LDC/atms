import { ref, computed, onMounted } from 'vue'
import api from '@/lib/api'
import type { DashboardData } from '@/types'

export function useDashboard() {
  const data = ref<DashboardData | null>(null)
  const loading = ref(true)
  const error = ref<string | null>(null)

  async function reload() {
    loading.value = true
    error.value = null
    try {
      data.value = await api.get<DashboardData>('/dashboard')
    } catch {
      error.value = 'Failed to load dashboard.'
    } finally {
      loading.value = false
    }
  }

  onMounted(reload)

  // RBAC key-gating: the backend omits a summary key entirely when the
  // current role can't see it, so presence — not the value — is the gate.
  const showPendingMr = computed(
    () => data.value?.summary.pending_maintenance_requests !== undefined,
  )
  const showOpenWo = computed(() => data.value?.summary.open_work_orders !== undefined)
  const showOverduePm = computed(() => data.value?.summary.overdue_pm_assignments !== undefined)
  const showRecentlyClosed = computed(
    () => data.value?.summary.recently_closed_work_orders !== undefined,
  )

  const pendingMrItems = computed(() => data.value?.pending_maintenance_requests ?? [])
  const openWoItems = computed(() => data.value?.open_work_orders ?? [])
  const overduePmItems = computed(() => data.value?.overdue_pm_assignments ?? [])
  const closedWoItems = computed(() => data.value?.recently_closed_work_orders ?? [])

  const hasActionRequired = computed(
    () => showPendingMr.value || showOpenWo.value || showOverduePm.value,
  )

  return {
    data,
    loading,
    error,
    reload,
    showPendingMr,
    showOpenWo,
    showOverduePm,
    showRecentlyClosed,
    pendingMrItems,
    openWoItems,
    overduePmItems,
    closedWoItems,
    hasActionRequired,
  }
}
