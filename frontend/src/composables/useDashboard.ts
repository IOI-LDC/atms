import { ref, onMounted } from 'vue'
import api from '@/lib/api'
import type { DashboardData } from '@/types'

export function useDashboard() {
  const data    = ref<DashboardData | null>(null)
  const loading = ref(true)
  const error   = ref<string | null>(null)

  onMounted(async () => {
    try {
      data.value = await api.get<DashboardData>('/dashboard')
    } catch {
      error.value = 'Failed to load dashboard.'
    } finally {
      loading.value = false
    }
  })

  return { data, loading, error }
}
