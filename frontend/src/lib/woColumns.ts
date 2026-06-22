import type { ColumnDef } from '@ioi-dev/vue-table'
import type { Priority, WorkOrder, WorkOrderStatus } from '@/types'
import { priorityLabel, woStatusLabel } from '@/lib/displayHelpers'
import type { FilterOption } from '@/lib/dataTableSource'

/**
 * Column definitions shared by all Work Order list tabs.
 *
 * Sortable fields limited to the backend whitelist (created_at, priority,
 * status, started_at, closed_at). `assigned_to` is a relation (UserRef) and is
 * not backend-sortable, so it stays sortable:false; its filter is deferred
 * (needs an assignee picker, not a plain select). Cell content is rendered in
 * the view's `#cell` slot; this only declares structure. Select filters use
 * fixed option lists (woFilterOptions) to avoid the server-mode "options from
 * loaded rows only" gap.
 */
export const woColumns: ColumnDef<WorkOrder>[] = [
  { field: 'number', header: 'Work Order', sortable: false },
  { field: 'asset', header: 'Asset', sortable: false },
  { field: 'priority', header: 'Priority', sortable: true, headerFilter: 'select' },
  { field: 'status', header: 'Status', sortable: true, headerFilter: 'select' },
  { field: 'assigned_to', header: 'Assigned To', sortable: false },
  { field: 'started_at', header: 'Started', sortable: true, type: 'date' },
  { field: 'created_at', header: 'Created', sortable: true, type: 'date' },
]

/**
 * Fixed option lists for the WO select filters, consumed by the view's
 * `#header-filter` slot. Labels reuse displayHelpers (single source of truth).
 */
export const woFilterOptions: Record<string, FilterOption[]> = {
  status: (
    ['open', 'in_progress', 'completed', 'closed', 'cancelled'] as WorkOrderStatus[]
  ).map((v) => ({ value: v, label: woStatusLabel(v) })),
  priority: (['low', 'medium', 'high', 'critical'] as Priority[]).map((v) => ({
    value: v,
    label: priorityLabel(v),
  })),
}
