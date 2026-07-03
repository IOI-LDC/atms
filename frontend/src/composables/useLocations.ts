import { ref, computed } from 'vue'
import api, { ApiError } from '@/lib/api'
import { fetchList } from '@/lib/dataTableSource'
import type { Asset, Location } from '@/types'
import { useAuthStore } from '@/stores/auth.store'

export function useLocations() {
  const auth = useAuthStore()

  // ── All locations (used by ManageLocationsView for CRUD table) ──────────────
  const locations = ref<Location[]>([])
  const locationsLoading = ref(false)
  const locationsError = ref<string | null>(null)

  // Active-only subset — used by picker dropdown and location filter bar.
  // Computed so it stays in sync whenever locations updates.
  const activeLocations = computed(() => locations.value.filter((l) => l.is_active))

  async function loadLocations(force = false) {
    if (locations.value.length > 0 && !force) return
    locationsLoading.value = true
    locationsError.value = null
    try {
      // Admin needs the full list (incl. inactive) for ManageLocationsView's
      // CRUD table. Manager/Logistics only reach the picker/filter, so the
      // active-only /locations endpoint is sufficient. Technician/Requester
      // lack viewAny — skip the fetch to avoid a 403.
      if (auth.isAdmin) {
        const res = await api.get<{ data: Location[] }>('/admin/locations')
        locations.value = res.data ?? []
      } else if (auth.isManager || auth.isLogistics) {
        const res = await api.get<{ data: Location[] }>('/locations')
        locations.value = res.data ?? []
      }
    } catch (e) {
      locations.value = []
      if (e instanceof ApiError && e.status === 403) {
        locationsError.value =
          'Location list not available for your role. Contact an administrator.'
      } else {
        locationsError.value = 'Failed to load locations.'
      }
    } finally {
      locationsLoading.value = false
    }
  }

  // ── Asset list for the Asset Location Update tab ────────────────────────────
  const assets = ref<Asset[]>([])
  const assetsLoading = ref(false)

  async function loadAssets(force = false) {
    if (assets.value.length > 0 && !force) return
    assetsLoading.value = true
    try {
      assets.value = await fetchList<Asset>('/assets', { is_active: true, sort: 'name:asc' })
    } catch {
      assets.value = []
    } finally {
      assetsLoading.value = false
    }
  }

  // ── Location CRUD mutations (Admin only — backend enforces) ─────────────────
  const saving = ref(false)
  const validationErrors = ref<Record<string, string[]> | null>(null)

  interface LocationPayload {
    name: string
    type: string
    code?: string | null
    parent_id?: number | null
    description?: string | null
    is_active?: boolean
  }

  async function createLocation(payload: LocationPayload): Promise<Location | null> {
    saving.value = true
    validationErrors.value = null
    try {
      const res = await api.post<{ data: Location }>('/admin/locations', payload)
      await loadLocations(true)
      return res.data
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) validationErrors.value = e.validationErrors
      return null
    } finally {
      saving.value = false
    }
  }

  async function updateLocation(
    id: number,
    payload: Partial<LocationPayload>,
  ): Promise<Location | null> {
    saving.value = true
    validationErrors.value = null
    try {
      const res = await api.patch<{ data: Location }>(`/admin/locations/${id}`, payload)
      await loadLocations(true)
      return res.data
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) validationErrors.value = e.validationErrors
      return null
    } finally {
      saving.value = false
    }
  }

  async function toggleLocationActive(location: Location): Promise<boolean> {
    saving.value = true
    validationErrors.value = null
    try {
      await api.patch<{ data: Location }>(`/admin/locations/${location.id}`, {
        is_active: !location.is_active,
      })
      await loadLocations(true)
      return true
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) validationErrors.value = e.validationErrors
      return false
    } finally {
      saving.value = false
    }
  }

  return {
    locations,
    activeLocations,
    locationsLoading,
    locationsError,
    loadLocations,
    assets,
    assetsLoading,
    loadAssets,
    saving,
    validationErrors,
    createLocation,
    updateLocation,
    toggleLocationActive,
  }
}
