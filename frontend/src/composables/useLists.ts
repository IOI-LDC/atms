import { ref, computed, reactive } from 'vue'
import api, { ApiError } from '@/lib/api'
import type { MasterDataItem, UsageReadingType, MaintenanceCategoryOption } from '@/types'

// ── Group registry ────────────────────────────────────────────────────────────
// Configurable vocabulary groups surfaced as selectable items in the rail.
// Maintenance Categories replaced the retired FA Subclass Type Codes group —
// the subclass reference is ERP-managed and no longer editable here (it still
// backs asset-tag type codes and the WO-form template picker).
export type ListKind = 'master_data' | 'reading_types' | 'maintenance_category'

export interface ListGroup {
  key: string
  label: string
  section: string
  kind: ListKind
}

export type ListItem = MasterDataItem | UsageReadingType | MaintenanceCategoryOption

export const LIST_GROUPS: ListGroup[] = [
  {
    key: 'maintenance_priorities',
    label: 'Maintenance Priorities',
    section: 'Master Data',
    kind: 'master_data',
  },
  {
    key: 'maintenance_categories',
    label: 'Maintenance Categories',
    section: 'Master Data',
    kind: 'maintenance_category',
  },
  {
    key: 'usage_reading_types',
    label: 'Usage Reading Types',
    section: 'Reading Types',
    kind: 'reading_types',
  },
]

// Rail sections in display order.
export const LIST_SECTIONS = ['Master Data', 'Reading Types'] as const

export function useLists() {
  // ── Active group ────────────────────────────────────────────────────────────
  const DEFAULT_GROUP = LIST_GROUPS[0]!
  const activeGroupKey = ref<string>(DEFAULT_GROUP.key)
  const activeGroup = computed<ListGroup>(
    () => LIST_GROUPS.find((g) => g.key === activeGroupKey.value) ?? DEFAULT_GROUP,
  )

  // Per-group cache so re-selecting a group is instant.
  const cache = reactive<Record<string, ListItem[]>>({})
  const loading = ref(false)
  const error = ref<string | null>(null)

  const items = computed<ListItem[]>(() => cache[activeGroupKey.value] ?? [])

  function collectionPath(group: ListGroup): string {
    switch (group.kind) {
      case 'master_data':
        return `/admin/master-data/${group.key}`
      case 'reading_types':
        return '/admin/usage-reading-types'
      case 'maintenance_category':
        return '/admin/maintenance-categories'
    }
  }

  function itemPath(group: ListGroup, item: ListItem): string {
    switch (group.kind) {
      case 'master_data':
        return `/admin/master-data/items/${(item as MasterDataItem).id}`
      case 'reading_types':
        return `/admin/usage-reading-types/${(item as UsageReadingType).id}`
      case 'maintenance_category':
        return `/admin/maintenance-categories/${(item as MaintenanceCategoryOption).code}`
    }
  }

  async function loadActive(force = false) {
    const group = activeGroup.value
    if (cache[group.key] && !force) return
    loading.value = true
    error.value = null
    try {
      const res = await api.get<{ data: ListItem[] }>(collectionPath(group))
      cache[group.key] = res.data ?? []
    } catch {
      error.value = 'Failed to load list items.'
    } finally {
      loading.value = false
    }
  }

  async function selectGroup(key: string) {
    if (activeGroupKey.value === key) return
    activeGroupKey.value = key
    await loadActive()
  }

  // ── Mutations ───────────────────────────────────────────────────────────────
  const saving = ref(false)
  const validationErrors = ref<Record<string, string[]> | null>(null)

  async function createItem(payload: Record<string, unknown>): Promise<boolean> {
    saving.value = true
    validationErrors.value = null
    try {
      await api.post(collectionPath(activeGroup.value), payload)
      await loadActive(true)
      return true
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) validationErrors.value = e.validationErrors
      return false
    } finally {
      saving.value = false
    }
  }

  async function updateItem(item: ListItem, payload: Record<string, unknown>): Promise<boolean> {
    saving.value = true
    validationErrors.value = null
    try {
      await api.patch(itemPath(activeGroup.value, item), payload)
      await loadActive(true)
      return true
    } catch (e) {
      if (e instanceof ApiError && e.validationErrors) validationErrors.value = e.validationErrors
      return false
    } finally {
      saving.value = false
    }
  }

  // is_active toggle — every list kind carries this field.
  async function toggleActive(item: ListItem): Promise<boolean> {
    return updateItem(item, { is_active: !(item as MasterDataItem | UsageReadingType | MaintenanceCategoryOption).is_active })
  }

  return {
    activeGroupKey,
    activeGroup,
    items,
    loading,
    error,
    loadActive,
    selectGroup,
    saving,
    validationErrors,
    createItem,
    updateItem,
    toggleActive,
  }
}
