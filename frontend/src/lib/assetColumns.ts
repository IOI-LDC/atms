import type { AppColumnDef as ColumnDef } from '@/lib/appTable'
import type { Asset, AssetMaintenanceStatus, AssetKind } from '@/types'
import { assetKindLabel, assetMaintenanceStatusLabel } from '@/lib/displayHelpers'
import type { FilterOption } from '@/lib/dataTableSource'

/**
 * Column definitions for the All Assets table.
 *
 * Notes:
 * - `fa_subclass_code` replaces `category` — the `category` column in the DB
 *   is empty for all assets; the real classification lives in fa_subclass_code.
 * - `current_location` is an object { id, name } — no headerFilter (location
 *   filtering is handled externally via the .asset-filter-bar select shown
 *   to Admin/Manager only).
 * - "Latest usage reading" and "PM status" are not returned by the list
 *   endpoint and are deferred to the asset detail page.
 */
export const assetColumns: ColumnDef<Asset>[] = [
  {
    field: 'asset_tag',
    header: 'Asset Tag',
    sortable: false,
  },
  {
    field: 'name',
    header: 'Name',
    sortable: true,
    // Give the primary identifier more room — asset names are descriptive.
    minWidth: 320,
  },
  {
    field: 'serial_number',
    header: 'SN',
    sortable: true,
  },
  {
    field: 'fa_subclass_code',
    header: 'Asset Class',
    sortable: true,
    headerFilter: 'select',
  },
  {
    field: 'asset_kind',
    header: 'Kind',
    sortable: false,
    headerFilter: 'select',
  },
  {
    field: 'maintenance_status',
    header: 'Status',
    sortable: true,
    headerFilter: 'select',
  },
  {
    field: 'current_location',
    header: 'Location',
    sortable: false,
  },
]

/**
 * All 18 known ERP FA subclass codes, sourced from the fa_subclass_type_codes
 * table. Used for the column header select filter AND the edit form's ERP Class
 * selector.
 */
export const FA_SUBCLASS_OPTIONS: FilterOption[] = [
  { value: 'MUD MOTOR', label: 'Mud Motor' },
  { value: 'MWD/LWD', label: 'MWD/LWD' },
  { value: 'DHT', label: 'DHT' },
  { value: 'NMDC', label: 'NMDC' },
  { value: 'MACHEQ', label: 'Machinery & Equipment' },
  { value: 'WHIPSTOCK', label: 'Whipstock' },
  { value: 'JARS', label: 'Jars' },
  { value: 'WIRELINE', label: 'Wireline' },
  { value: 'SHOCK SUBS', label: 'Shock Subs' },
  { value: 'COMPLETION', label: 'Completion' },
  { value: 'FURNOFF', label: 'Furnoff' },
  { value: 'RTM', label: 'RTM' },
  { value: 'GYRO', label: 'Gyro' },
  { value: 'ORGEXP', label: 'Org Exp' },
  { value: 'PROPPLT', label: 'Propplt' },
  { value: 'VEH', label: 'Vehicle' },
  { value: 'COMPPER', label: 'Completion Perforating' },
  { value: 'HOLEOPENER', label: 'Hole Opener' },
]

/** Fixed select-filter option lists. Labels reuse displayHelpers (single source of truth). */
export const assetFilterOptions: Record<string, FilterOption[]> = {
  fa_subclass_code: FA_SUBCLASS_OPTIONS,
  asset_kind: (['asset', 'package', 'component'] as AssetKind[]).map((v) => ({
    value: v,
    label: assetKindLabel(v),
  })),
  maintenance_status: (['enrolled', 'withdrawn'] as AssetMaintenanceStatus[]).map((v) => ({
    value: v,
    label: assetMaintenanceStatusLabel(v),
  })),
}
