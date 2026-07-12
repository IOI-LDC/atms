import { computed, ref } from 'vue'
import api from '@/lib/api'
import type { AssetKind, BookingReport } from '@/types'

/** Filters accepted by GET /api/reports/booking (R-13). */
export interface BookingFilters {
  location_id?: string | number
  asset_kind?: AssetKind | ''
}

/**
 * R-13 Asset Booking / Availability — bounded `{ summary, items }`. Booked vs
 * freely-available assets, one row per location.
 */
export function useBookingReport() {
  const data = ref<BookingReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function load(filters: BookingFilters = {}): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const query: Record<string, string | number> = {}
      if (filters.location_id) {
        query.location_id = filters.location_id
      }
      if (filters.asset_kind) {
        query.asset_kind = filters.asset_kind
      }
      data.value = await api.get<BookingReport>('/reports/booking', query)
    } catch {
      error.value = 'Failed to load the booking report.'
      data.value = null
    } finally {
      loading.value = false
    }
  }

  const summary = computed(() => data.value?.summary ?? null)
  const rows = computed(() => data.value?.items ?? [])

  return { data, loading, error, load, summary, rows }
}
