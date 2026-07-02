import type { AppColumnDef as ColumnDef } from '@/lib/appTable'
import type { Asset, AssetMaintenanceStatus, AssetKind, FaSubclassTypeCode } from '@/types'
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
 * Friendly display labels for known ERP FA subclass codes. The live
 * fa_subclass_type_codes table (fetched via useListOptions().loadFaSubclasses)
 * is the source of truth for which codes exist — this map only formats them
 * for display. Codes without a curated entry (e.g. a code the ERP adds later)
 * fall back to the raw code via `faSubclassLabel()`.
 */
const FA_SUBCLASS_LABELS: Record<string, string> = {
  'MUD MOTOR': 'Mud Motor',
  'MWD/LWD': 'MWD/LWD',
  DHT: 'DHT',
  NMDC: 'NMDC',
  MACHEQ: 'Machinery & Equipment',
  WHIPSTOCK: 'Whipstock',
  JARS: 'Jars',
  WIRELINE: 'Wireline',
  'SHOCK SUBS': 'Shock Subs',
  COMPLETION: 'Completion',
  FURNOFF: 'Furnoff',
  RTM: 'RTM',
  GYRO: 'Gyro',
  ORGEXP: 'Org Exp',
  PROPPLT: 'Propplt',
  VEH: 'Vehicle',
  COMPPER: 'Completion Perforating',
  HOLEOPENER: 'Hole Opener',
}

export function faSubclassLabel(code: string): string {
  return FA_SUBCLASS_LABELS[code] ?? code
}

/** Maps the live fa_subclass_type_codes list into select-filter options. */
export function toFaSubclassFilterOptions(codes: FaSubclassTypeCode[]): FilterOption[] {
  return codes.map((c) => ({ value: c.fa_subclass_code, label: faSubclassLabel(c.fa_subclass_code) }))
}

/**
 * Fixed select-filter option lists for fields with a small closed set of
 * values. `fa_subclass_code` is NOT included here — it's live data, so views
 * merge `toFaSubclassFilterOptions(faSubclasses.value)` into a computed at
 * runtime instead. Labels reuse displayHelpers (single source of truth).
 */
export const assetFilterOptions: Record<string, FilterOption[]> = {
  asset_kind: (['asset', 'package', 'component'] as AssetKind[]).map((v) => ({
    value: v,
    label: assetKindLabel(v),
  })),
  maintenance_status: (['enrolled', 'withdrawn'] as AssetMaintenanceStatus[]).map((v) => ({
    value: v,
    label: assetMaintenanceStatusLabel(v),
  })),
}
