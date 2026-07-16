import type { AppColumnDef as ColumnDef } from '@/lib/appTable'
import type { Part } from '@/types'
import type { FilterOption } from '@/lib/dataTableSource'

/**
 * Column definitions for the Parts Reference table.
 *
 * `category` has no headerFilter option list of its own — it's live data
 * (55 seed rows across 11 categories today, real ERP categories later), so
 * PartsView merges `toCategoryFilterOptions(all.rows.value)` into a computed
 * at runtime instead, same pattern as `fa_subclass_code` in assetColumns.ts.
 */
export const partColumns: ColumnDef<Part>[] = [
  {
    field: 'erp_part_code',
    header: 'ERP Code',
    sortable: true,
  },
  {
    field: 'name',
    header: 'Name',
    sortable: true,
    minWidth: 280,
  },
  {
    field: 'category',
    header: 'Category',
    sortable: true,
    headerFilter: 'select',
  },
  {
    field: 'unit_of_measure',
    header: 'Unit',
    sortable: false,
  },
  {
    field: 'available_quantity',
    header: 'Qty',
    sortable: true,
    type: 'number',
  },
  {
    field: 'is_active',
    header: 'Status',
    sortable: false,
  },
]

/** Unique category options derived from the loaded parts list — not hardcoded. */
export function toCategoryFilterOptions(parts: Part[]): FilterOption[] {
  const seen = new Set<string>()
  const options: FilterOption[] = []
  for (const p of parts) {
    if (p.category && !seen.has(p.category)) {
      seen.add(p.category)
      options.push({ value: p.category, label: p.category })
    }
  }
  return options.sort((a, b) => a.label.localeCompare(b.label))
}
