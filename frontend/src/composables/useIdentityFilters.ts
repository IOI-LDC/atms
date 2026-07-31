import { computed, ref, unref, type MaybeRefOrGetter } from 'vue'
import type { FilterOption } from '@/lib/dataTableSource'
import type { AssetIdentity, PartIdentity } from '@/types'

/** Anything carrying an identity we can filter on. */
type Identity = Pick<AssetIdentity, 'serial_number' | 'size' | 'size_inches' | 'maintenance_category'> &
  Partial<Pick<PartIdentity, 'part_number'>>

/**
 * Serial-number / size / category filtering for the table toolbars.
 *
 * Filters the rows *before* they reach AppDataTable rather than driving its
 * column filters, because the identity renders as one package with no dedicated
 * S/N or Size column to hang a header filter on.
 *
 * Size options are keyed by the exact canonical value (`6.75000`) and labelled
 * with the O&G display (`6 3/4"`), so two rows spelled differently in the
 * source data still collapse to one option.
 *
 * @param rows      the full row set
 * @param identityOf how to reach the identity on a row (defaults to the row itself)
 */
export function useIdentityFilters<T>(
  rows: MaybeRefOrGetter<T[]>,
  identityOf: (row: T) => Identity | null | undefined = (row) => row as unknown as Identity,
) {
  const serialQuery = ref('')
  const sizeValue = ref('')
  const categoryValue = ref('')
  const partNumberQuery = ref('')

  const allRows = computed<T[]>(() =>
    typeof rows === 'function' ? (rows as () => T[])() : (unref(rows) as T[]),
  )

  /** Distinct sizes present in the data, ordered numerically. */
  const sizeOptions = computed<FilterOption[]>(() => {
    const seen = new Map<string, string>()

    for (const row of allRows.value) {
      const identity = identityOf(row)
      if (identity?.size_inches && identity.size) {
        seen.set(identity.size_inches, identity.size)
      }
    }

    return [...seen.entries()]
      .sort(([a], [b]) => Number(a) - Number(b))
      .map(([value, label]) => ({ value, label }))
  })

  /** Distinct Maintenance Categories present in the data, alphabetical. */
  const categoryOptions = computed<FilterOption[]>(() => {
    const seen = new Map<string, string>()

    for (const row of allRows.value) {
      const category = identityOf(row)?.maintenance_category
      if (category) {
        seen.set(String(category.id), category.name)
      }
    }

    return [...seen.entries()]
      .sort(([, a], [, b]) => a.localeCompare(b))
      .map(([value, label]) => ({ value, label }))
  })

  const hasActiveFilter = computed(
    () =>
      serialQuery.value.trim() !== '' ||
      partNumberQuery.value.trim() !== '' ||
      sizeValue.value !== '' ||
      categoryValue.value !== '',
  )

  const filteredRows = computed<T[]>(() => {
    if (!hasActiveFilter.value) {
      return allRows.value
    }

    const serial = serialQuery.value.trim().toLowerCase()
    const partNumber = partNumberQuery.value.trim().toLowerCase()

    return allRows.value.filter((row) => {
      const identity = identityOf(row)
      if (!identity) {
        return false
      }

      if (serial && !(identity.serial_number ?? '').toLowerCase().includes(serial)) {
        return false
      }
      if (partNumber && !(identity.part_number ?? '').toLowerCase().includes(partNumber)) {
        return false
      }
      // Size matches on the exact canonical value, never the formatted text.
      if (sizeValue.value && identity.size_inches !== sizeValue.value) {
        return false
      }
      if (categoryValue.value && String(identity.maintenance_category?.id ?? '') !== categoryValue.value) {
        return false
      }
      return true
    })
  })

  function reset() {
    serialQuery.value = ''
    partNumberQuery.value = ''
    sizeValue.value = ''
    categoryValue.value = ''
  }

  return {
    serialQuery,
    partNumberQuery,
    sizeValue,
    categoryValue,
    sizeOptions,
    categoryOptions,
    hasActiveFilter,
    filteredRows,
    reset,
  }
}
