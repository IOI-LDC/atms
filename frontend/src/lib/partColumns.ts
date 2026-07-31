import type { AppColumnDef as ColumnDef } from '@/lib/appTable'
import type { Part } from '@/types'

/**
 * Column definitions for the Parts Reference table.
 *
 * The Name column renders the full identity package (name + Part Number, Size
 * and Maintenance Category badges), so those have no columns of their own —
 * they are filtered from the toolbar via `useIdentityFilters`.
 */
export const partColumns: ColumnDef<Part>[] = [
  {
    field: 'name',
    header: 'Name',
    sortable: true,
    minWidth: 320,
    // The cell renders the full identity package, so its badge values join the
    // toolbar search. `erp_part_code` is deliberately absent.
    searchFields: ['part_number', 'size', 'maintenance_category.name'],
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


