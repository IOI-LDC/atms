import { ref } from 'vue'
import api from '@/lib/api'
import type { MasterDataItem, UsageReadingType, FaSubclassTypeCode } from '@/types'
import type { FilterOption } from '@/lib/dataTableSource'

/**
 * Fallback shown if GET /api/list-options/maintenance_priorities is
 * unreachable. Values match the seeded master_data_items rows so MR
 * create/edit never breaks on a network failure.
 */
export const DEFAULT_PRIORITIES: FilterOption[] = [
  { value: 'low', label: 'Low' },
  { value: 'medium', label: 'Medium' },
  { value: 'high', label: 'High' },
  { value: 'critical', label: 'Critical' },
]

/**
 * Live dropdown-option lists backed by GET /api/list-options/{group} —
 * active-only rows, readable by every authenticated role (not Admin-gated).
 * Each `loadX()` both updates the returned ref and resolves with the value,
 * so callers can either watch the ref or await the fetch directly.
 */
export function useListOptions() {
  const priorities = ref<FilterOption[]>(DEFAULT_PRIORITIES)
  const readingTypes = ref<UsageReadingType[]>([])
  const faSubclasses = ref<FaSubclassTypeCode[]>([])

  const prioritiesLoading = ref(false)
  const readingTypesLoading = ref(false)
  const faSubclassesLoading = ref(false)

  async function loadPriorities(): Promise<FilterOption[]> {
    prioritiesLoading.value = true
    try {
      const res = await api.get<{ data: MasterDataItem[] }>('/list-options/maintenance_priorities')
      const items = res.data ?? []
      priorities.value =
        items.length > 0
          ? items.map((i) => ({ value: i.value, label: i.label }))
          : DEFAULT_PRIORITIES
    } catch {
      priorities.value = DEFAULT_PRIORITIES
    } finally {
      prioritiesLoading.value = false
    }
    return priorities.value
  }

  async function loadReadingTypes(): Promise<UsageReadingType[]> {
    readingTypesLoading.value = true
    try {
      const res = await api.get<{ data: UsageReadingType[] }>('/list-options/usage_reading_types')
      readingTypes.value = res.data ?? []
    } catch {
      readingTypes.value = []
    } finally {
      readingTypesLoading.value = false
    }
    return readingTypes.value
  }

  async function loadFaSubclasses(): Promise<FaSubclassTypeCode[]> {
    faSubclassesLoading.value = true
    try {
      const res = await api.get<{ data: FaSubclassTypeCode[] }>(
        '/list-options/fa_subclass_type_codes',
      )
      faSubclasses.value = res.data ?? []
    } catch {
      faSubclasses.value = []
    } finally {
      faSubclassesLoading.value = false
    }
    return faSubclasses.value
  }

  return {
    priorities,
    prioritiesLoading,
    loadPriorities,
    readingTypes,
    readingTypesLoading,
    loadReadingTypes,
    faSubclasses,
    faSubclassesLoading,
    loadFaSubclasses,
  }
}
