import { ref } from 'vue'
import api from '@/lib/api'
import type {
  MasterDataItem,
  UsageReadingType,
  FaSubclassTypeCode,
  MaintenanceCategoryOption,
} from '@/types'
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
  const maintenanceCategories = ref<MaintenanceCategoryOption[]>([])
  const assetSizes = ref<FilterOption[]>([])
  const assetConditions = ref<FilterOption[]>([])
  /** The `is_default` row — what a new asset is pre-set to, and what a close resets to. */
  const defaultAssetCondition = ref<string | null>(null)

  const prioritiesLoading = ref(false)
  const readingTypesLoading = ref(false)
  const faSubclassesLoading = ref(false)
  const maintenanceCategoriesLoading = ref(false)
  const assetSizesLoading = ref(false)
  const assetConditionsLoading = ref(false)

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

  /**
   * The controlled Maintenance Category vocabulary. Read-only — values are
   * introduced only by the controlled workbook import, so this list is empty
   * until that import has run.
   */
  async function loadMaintenanceCategories(): Promise<MaintenanceCategoryOption[]> {
    maintenanceCategoriesLoading.value = true
    try {
      const res = await api.get<{ data: MaintenanceCategoryOption[] }>(
        '/list-options/maintenance_categories',
      )
      maintenanceCategories.value = res.data ?? []
    } catch {
      maintenanceCategories.value = []
    } finally {
      maintenanceCategoriesLoading.value = false
    }
    return maintenanceCategories.value
  }

  /**
   * Sizes already in use across assets and parts. `value` is the exact
   * canonical decimal to submit; `label` is O&G fractional notation.
   */
  async function loadAssetSizes(): Promise<FilterOption[]> {
    assetSizesLoading.value = true
    try {
      const res = await api.get<{ data: FilterOption[] }>('/list-options/asset_sizes')
      assetSizes.value = res.data ?? []
    } catch {
      assetSizes.value = []
    } finally {
      assetSizesLoading.value = false
    }
    return assetSizes.value
  }

  /**
   * The asset condition vocabulary. Unlike priorities there is no hardcoded
   * fallback: the values are LDC's to add and retire, so inventing a client-side
   * list would offer conditions the API would then reject.
   */
  async function loadAssetConditions(): Promise<FilterOption[]> {
    assetConditionsLoading.value = true
    try {
      const res = await api.get<{ data: MasterDataItem[] }>('/list-options/asset_conditions')
      const items = res.data ?? []
      assetConditions.value = items.map((i) => ({ value: i.value, label: i.label }))
      defaultAssetCondition.value = items.find((i) => i.is_default)?.value ?? null
    } catch {
      assetConditions.value = []
      defaultAssetCondition.value = null
    } finally {
      assetConditionsLoading.value = false
    }
    return assetConditions.value
  }

  return {
    priorities,
    prioritiesLoading,
    loadPriorities,
    assetConditions,
    assetConditionsLoading,
    defaultAssetCondition,
    loadAssetConditions,
    readingTypes,
    readingTypesLoading,
    loadReadingTypes,
    faSubclasses,
    faSubclassesLoading,
    loadFaSubclasses,
    maintenanceCategories,
    maintenanceCategoriesLoading,
    loadMaintenanceCategories,
    assetSizes,
    assetSizesLoading,
    loadAssetSizes,
  }
}
