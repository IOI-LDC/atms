import type { AppColumnDef as ColumnDef } from '@/lib/appTable'
import type { Asset, AssetKind, AssetOperationalStatus, MaintenanceCategoryOption } from '@/types'
import { assetKindLabel, operationalStatusLabel } from '@/lib/displayHelpers'
import type { FilterOption } from '@/lib/dataTableSource'

/**
 * Column definitions for the All Assets table.
 *
 * Notes:
 * - The Name column renders the full identity package (name + Serial Number,
 *   Size and Maintenance Category badges), so there is no separate SN column.
 * - `maintenance_category.name` is the ATMS-owned classification, filterable
 *   via a select dropdown populated from /list-options/maintenance_categories.
 * - `current_location` is an object { id, name } — no headerFilter (location
 *   filtering is handled externally via the select in the table's #toolbar
 *   slot, shown to Admin/Manager/Logistics only).
 * - The status column shows `operational_status` (Active / Under Maintenance /
 *   Down / Retired) — the business-relevant "is it usable right now?" signal
 *   that moves with the MR/WO workflow. `maintenance_status` was dropped from
 *   the table: 400/400 assets read `enrolled`, so the badge carried no
 *   information; it remains on the asset detail page.
 * - "Latest usage reading" and "PM status" are not returned by the list
 *   endpoint and are deferred to the asset detail page.
 */
export const assetColumns: ColumnDef<Asset>[] = [
  {
    field: 'asset_tag',
    header: 'Asset Tag',
    sortable: false,
    minWidth: 110,
  },
  {
    field: 'name',
    header: 'Name',
    sortable: true,
    // Give the primary identifier more room — asset names are descriptive.
    minWidth: 320,
    // The cell renders the full identity package, so its badge values join the
    // toolbar search. `erp_asset_code` is deliberately absent — not displayed,
    // not searched.
    searchFields: ['serial_number', 'size', 'maintenance_category.name'],
  },
  {
    field: 'maintenance_category.name',
    header: 'Category',
    sortable: true,
    headerFilter: 'select',
    minWidth: 150,
  },
  {
    field: 'asset_kind',
    header: 'Kind',
    sortable: false,
    headerFilter: 'select',
    minWidth: 100,
  },
  {
    field: 'operational_status',
    header: 'Operational Status',
    sortable: true,
    headerFilter: 'select',
    minWidth: 130,
  },
  {
    field: 'current_location',
    header: 'Location',
    sortable: false,
    minWidth: 160,
  },
]

/** Maps the live maintenance_categories list into select-filter options. */
export function toMaintenanceCategoryFilterOptions(
  categories: MaintenanceCategoryOption[],
): FilterOption[] {
  return categories.map((c) => ({
    value: c.name,
    label: c.name,
  }))
}

/**
 * Maps maintenance_categories into select-filter options keyed by **id**, for
 * report endpoints that filter server-side on `maintenance_category_id`.
 *
 * Distinct from `toMaintenanceCategoryFilterOptions` above, which keys by name
 * because AppDataTable filters the rendered column value in the browser.
 */
export function toMaintenanceCategoryIdFilterOptions(
  categories: MaintenanceCategoryOption[],
): FilterOption[] {
  return categories.map((c) => ({
    value: String(c.id),
    label: c.name,
  }))
}

/**
 * Fixed select-filter option lists for fields with a small closed set of
 * values. `maintenance_category.name` is NOT included here — it's live data,
 * so views merge `toMaintenanceCategoryFilterOptions(categories)` into a
 * computed at runtime instead. Labels reuse displayHelpers (single source of
 * truth).
 */
export const assetFilterOptions: Record<string, FilterOption[]> = {
  asset_kind: (['asset', 'package', 'component'] as AssetKind[]).map((v) => ({
    value: v,
    label: assetKindLabel(v),
  })),
  operational_status: (
    ['active', 'under_maintenance', 'down', 'inactive'] as AssetOperationalStatus[]
  ).map((v) => ({
    value: v,
    label: operationalStatusLabel(v),
  })),
}
