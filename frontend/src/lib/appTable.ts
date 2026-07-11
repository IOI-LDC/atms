import type { FilterOption } from './dataTableSource'

/**
 * Column definition for {@link AppDataTable} — a framework-agnostic shape
 * consumed by the view-level column modules (assetColumns / mrColumns /
 * woColumns). `AppDataTable` maps these onto the underlying headless table
 * engine (TanStack Table) internally, so the column files never depend on a
 * specific table library and stay plain data.
 *
 * Fields:
 * - `field`        — the row property the column reads (also the column's
 *                    identity key). The `#cell` slot receives the full typed
 *                    `row`, so consumers usually read from `row` directly.
 * - `header`       — column header label.
 * - `sortable`     — enables click-to-sort on the header (default false).
 * - `headerFilter` — `'select'` (exact-match dropdown) or `'text'` (substring).
 * - `type`         — hint for sort/formatting (`'date'` compares ISO strings).
 * - `minWidth`     — minimum column width in px (applied via `<colgroup>`).
 * - `comparator`   — custom sort ordering: `(valueA, valueB, rowA, rowB)`.
 * - `searchFields` — extra row-property keys folded into the toolbar global
 *                    search for this column. Use when a column visually renders
 *                    more than its own `field` (e.g. the Name column also shows
 *                    `erp_asset_code` as secondary text) so the search matches
 *                    what the user sees. Not rendered or sorted — search only.
 */
export interface AppColumnDef<T = Record<string, unknown>> {
  field: string
  header: string
  sortable?: boolean
  headerFilter?: 'select' | 'text'
  type?: 'date' | 'number' | 'text'
  minWidth?: number
  align?: 'center' | 'right'
  comparator?: (valueA: unknown, valueB: unknown, rowA: T, rowB: T) => number
  searchFields?: string[]
}

export type { FilterOption }
