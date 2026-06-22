import type { ColumnDef } from '@ioi-dev/vue-table'
import type {
  MaintenanceRequest,
  MaintenanceRequestStatus,
  MrType,
  Priority,
} from '@/types'
import { mrStatusLabel, mrTypeLabel, priorityLabel } from '@/lib/displayHelpers'
import type { FilterOption } from '@/lib/dataTableSource'

export type { FilterOption }

/**
 * Column definitions shared by all Maintenance Request list tabs.
 *
 * Sortable fields are limited to the backend whitelist (created_at, priority,
 * status). Cell content is rendered in the view's `#cell` slot; this only
 * declares structure. Select filters use fixed option lists (`mrFilterOptions`)
 * so the dropdowns show the full enum regardless of what's currently loaded.
 */
export const mrColumns: ColumnDef<MaintenanceRequest>[] = [
  { field: 'number', header: 'Request', sortable: false },
  { field: 'asset', header: 'Asset', sortable: false },
  { field: 'priority', header: 'Priority', sortable: true, headerFilter: 'select' },
  { field: 'status', header: 'Status', sortable: true, headerFilter: 'select' },
  { field: 'type', header: 'Type', sortable: false, headerFilter: 'select' },
  { field: 'created_at', header: 'Created', sortable: true, type: 'date' },
  { field: 'description', header: 'Description', sortable: false },
]

/**
 * Fixed option lists for the MR select filters, consumed by AppDataTable's
 * `#header-filter` slot. Labels reuse displayHelpers (single source of truth).
 */
export const mrFilterOptions: Record<string, FilterOption[]> = {
  status: (
    ['pending_review', 'rejected', 'converted', 'cancelled'] as MaintenanceRequestStatus[]
  ).map((v) => ({ value: v, label: mrStatusLabel(v) })),
  priority: (['low', 'medium', 'high', 'critical'] as Priority[]).map((v) => ({
    value: v,
    label: priorityLabel(v),
  })),
  type: (['corrective', 'preventive'] as MrType[]).map((v) => ({
    value: v,
    label: mrTypeLabel(v),
  })),
}
