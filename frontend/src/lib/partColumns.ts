import type { AppColumnDef as ColumnDef } from '@/lib/appTable'
import type { Part } from '@/types'

/**
 * Column definitions for the Parts Reference table.
 *
 * The Name column renders the full identity package (name + ERP part code,
 * Part Number, Size and Maintenance Category badges), so those have no columns
 * of their own — they are filtered from the toolbar via `useIdentityFilters`.
 *
 * `erp_part_code` is the exception: it gets a dedicated **Part Number** column,
 * because it is the value LDC scan the list for and a badge inside the Name cell
 * is not a column you can sort or scan down.
 *
 * It is also the *only* part identifier the UI shows. A second column,
 * `part_number`, held a supplier's code on 3 of 734 parts and sat under a
 * near-identical label; the two were routinely confused, and the filter box
 * labelled "Part Number" searched the near-empty one (2026-08-17).
 */
export const partColumns: ColumnDef<Part>[] = [
  {
    field: 'erp_part_code',
    header: 'Part Number',
    sortable: true,
    minWidth: 160,
    searchFields: ['erp_part_code'],
  },
  {
    field: 'name',
    header: 'Name',
    sortable: true,
    minWidth: 700,
    // The cell renders the full identity package, so its badge values join the
    // toolbar search. `erp_part_code` is not repeated here — it has its own
    // searchable column above.
    searchFields: ['size', 'maintenance_category.name'],
  },
  {
    field: 'unit_of_measure',
    header: 'Unit',
    sortable: false,
    minWidth: 100,
  },
  {
    field: 'available_quantity',
    header: 'Qty',
    sortable: true,
    type: 'number',
    minWidth: 100,
  },
  {
    field: 'is_active',
    header: 'Status',
    sortable: false,
    minWidth: 100,
  },
]
