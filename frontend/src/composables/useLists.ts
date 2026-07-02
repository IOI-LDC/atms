import { ref, computed, reactive } from 'vue'
import api, { ApiError } from '@/lib/api'
import type { MasterDataItem, UsageReadingType, FaSubclassTypeCode } from '@/types'

// ── Group registry ────────────────────────────────────────────────────────────
// Two backing data sources, surfaced as three selectable groups in the rail.
// WO/asset/sub-statuses and the two dead category groups were removed here —
// they're Enum-backed state machines or unbacked concepts, not configurable
// vocab. See .kilo/plans/1783001396791-admin-lists-dropdowns-cleanup.md.
export type ListKind = 'master_data' | 'reading_types' | 'fa_subclass'

export interface ListGroup {
  key: string
  label: string
  section: string
  kind: ListKind
}

export type ListItem = MasterDataItem | UsageReadingType | FaSubclassTypeCode

export const LIST_GROUPS: ListGroup[] = [
  { key: 'maintenance_priorities',        label: 'Maintenance Priorities',  section: 'Master Data',   kind: 'master_data' },
  { key: 'usage_reading_types',           label: 'Usage Reading Types',     section: 'Reading Types', kind: 'reading_types' },
  { key: 'fa_subclass_type_codes',        label: 'FA Subclass Type Codes',  section: 'ERP Reference', kind: 'fa_subclass' },
]

// Rail sections in display order.
export const LIST_SECTIONS = ['Master Data', 'Reading Types', 'ERP Reference'] as const

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
      case 'master_data':  return `/admin/master-data/${group.key}`
      case 'reading_types': return '/admin/usage-reading-types'
      case 'fa_subclass':   return '/admin/fa-subclass-type-codes'
    }
  }

  function itemPath(group: ListGroup, item: ListItem): string {
    switch (group.kind) {
      case 'master_data':  return `/admin/master-data/items/${(item as MasterDataItem).id}`
      case 'reading_types': return `/admin/usage-reading-types/${(item as UsageReadingType).id}`
      case 'fa_subclass':   return `/admin/fa-subclass-type-codes/${(item as FaSubclassTypeCode).fa_subclass_code}`
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

  // is_active toggle — only master_data + reading_types carry this field.
  async function toggleActive(item: MasterDataItem | UsageReadingType): Promise<boolean> {
    return updateItem(item, { is_active: !item.is_active })
  }

  // Hard delete — only FA subclass type codes support DELETE.
  async function deleteItem(item: FaSubclassTypeCode): Promise<boolean> {
    saving.value = true
    try {
      await api.delete(`/admin/fa-subclass-type-codes/${item.fa_subclass_code}`)
      await loadActive(true)
      return true
    } catch {
      return false
    } finally {
      saving.value = false
    }
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
    deleteItem,
  }
}
