import type { AgingBucket, AssetKind, PmComplianceGroupBy } from '@/types'

/**
 * Static enum option lists for report filter bars. Priority options come from
 * the live master-data endpoint (useListOptions); everything here is a fixed
 * backend enum, so it lives as a constant rather than a fetch.
 */

export const ASSET_KIND_OPTIONS: { value: AssetKind; label: string }[] = [
  { value: 'asset', label: 'Asset' },
  { value: 'package', label: 'Package' },
  { value: 'component', label: 'Component' },
]

export const OPERATIONAL_STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: 'active', label: 'Active' },
  { value: 'under_maintenance', label: 'Under Maintenance' },
  { value: 'down', label: 'Down' },
  { value: 'inactive', label: 'Inactive' },
]

export const AGING_BUCKET_OPTIONS: { value: AgingBucket; label: string }[] = [
  { value: '0-7', label: '0–7 days' },
  { value: '8-30', label: '8–30 days' },
  { value: '31-90', label: '31–90 days' },
  { value: '91+', label: '91+ days' },
]

/** R-14 backlog status scope. */
export const WO_BACKLOG_STATUS_OPTIONS: { value: 'both' | 'open' | 'in_progress'; label: string }[] =
  [
    { value: 'both', label: 'Open & In Progress' },
    { value: 'open', label: 'Open' },
    { value: 'in_progress', label: 'In Progress' },
  ]

/** R-7 grouping dimension. */
export const PM_COMPLIANCE_GROUP_BY_OPTIONS: { value: PmComplianceGroupBy; label: string }[] = [
  { value: 'rule', label: 'PM Rule' },
  { value: 'asset', label: 'Asset' },
  { value: 'location', label: 'Location' },
]

/** R-1 forward horizon (days). String values for Select compatibility. */
export const PM_HORIZON_OPTIONS: { value: string; label: string }[] = [
  { value: '7', label: 'Next 7 days' },
  { value: '14', label: 'Next 14 days' },
  { value: '30', label: 'Next 30 days' },
  { value: '60', label: 'Next 60 days' },
  { value: '90', label: 'Next 90 days' },
]

/** R-3 MTBF / R-6 Bad-Actor dimension. "category" resolves to fa_subclass_code server-side. */
export const DIMENSION_GROUP_BY_OPTIONS: { value: 'asset' | 'category' | 'location'; label: string }[] =
  [
    { value: 'asset', label: 'Asset' },
    { value: 'category', label: 'Asset Class' },
    { value: 'location', label: 'Location' },
  ]

/** R-4 MTTR dimension (technician instead of location). */
export const MTTR_GROUP_BY_OPTIONS: { value: 'asset' | 'category' | 'technician'; label: string }[] =
  [
    { value: 'asset', label: 'Asset' },
    { value: 'category', label: 'Asset Class' },
    { value: 'technician', label: 'Technician' },
  ]

/** R-6 Bad-Actor "top N" cap. */
export const BAD_ACTOR_LIMIT_OPTIONS: { value: string; label: string }[] = [
  { value: '10', label: 'Top 10' },
  { value: '25', label: 'Top 25' },
  { value: '50', label: 'Top 50' },
]

/** R-21 PM suppression decision types. Backend validates only these two (Rule::in). */
export const PM_DECISION_TYPE_OPTIONS: { value: string; label: string }[] = [
  { value: 'rejected', label: 'Rejected' },
  { value: 'cancelled', label: 'Cancelled' },
]

/** R-16 throughput status — union of MR + WO statuses; applies to whichever source supports it. */
export const THROUGHPUT_STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: 'pending_review', label: 'MR: Pending Review' },
  { value: 'converted', label: 'MR: Converted' },
  { value: 'rejected', label: 'MR: Rejected' },
  { value: 'open', label: 'WO: Open' },
  { value: 'in_progress', label: 'WO: In Progress' },
  { value: 'completed', label: 'WO: Completed' },
  { value: 'closed', label: 'WO: Closed' },
  { value: 'cancelled', label: 'Cancelled (MR or WO)' },
]

/** Backward date window [from, to] as yyyy-MM-dd strings (company-local calendar day). */
export function reportDateWindow(days: number): { from: string; to: string } {
  const to = new Date()
  const from = new Date(to)
  from.setDate(from.getDate() - days)
  const iso = (d: Date) => d.toLocaleDateString('en-CA')
  return { from: iso(from), to: iso(to) }
}
