// ── Enums / unions ────────────────────────────────────────────────────────────

export type RoleCode =
  | 'administrator'
  | 'maintenance_manager'
  | 'technician'
  | 'logistics'
  | 'requester'
  | 'service'

export type MaintenanceRequestStatus = 'pending_review' | 'rejected' | 'converted' | 'cancelled'
export type WorkOrderStatus = 'open' | 'in_progress' | 'completed' | 'closed' | 'cancelled'
export type PmTriggerType = 'date' | 'reading' | 'date_or_reading'
export type Priority = 'low' | 'medium' | 'high' | 'critical'
export type MrType = 'corrective' | 'preventive'
export type MeterReadingSource = 'user' | 'manual'
export type ErpSyncStatus = 'running' | 'success' | 'partial' | 'failed'
export type AssetMaintenanceStatus = 'enrolled' | 'withdrawn'
export type AssetKind = 'asset' | 'package' | 'component'
export type AssetMaintenanceSubStatus =
  | 'installed'
  | 'ready' // enrolled sub-statuses (component/package)
  | 'lih'
  | 'dbr'
  | 'disposed'
  | 'scrapped'
  | 'other' // withdrawn sub-statuses
export type WoFormFieldType = 'boolean' | 'numeric' | 'text'

// ── Shared fragments ──────────────────────────────────────────────────────────

export interface Role {
  id: number
  code: RoleCode
  name: string
  description?: string
}

/** Minimal asset reference embedded in other resources. */
export interface AssetRef {
  id: number
  name: string
  erp_asset_code: string
  operational_status?: string
}

/** Minimal user reference. `email` only visible to Admin/Manager. */
export interface UserRef {
  id: number
  name: string
  email?: string
}

/** A Work Order assignee candidate (active Technician or Maintenance Manager). */
export interface Assignee {
  id: number
  name: string
  role: string
}

export interface LocationRef {
  id: number
  name: string
  type?: string
}

// ── Auth ──────────────────────────────────────────────────────────────────────

/** Returned by GET /api/auth/me and POST /api/auth/login as { user: User }. */
export interface User {
  id: number
  name: string
  email: string
  is_active: boolean
  activated_at: string | null
  email_verified_at: string | null
  emp_id: string | null
  employee_id: number | null
  role: Role
  created_at: string
  updated_at: string
}

// ── Assets ────────────────────────────────────────────────────────────────────

export interface Asset {
  id: number
  erp_asset_code: string
  /** ERP FA subclass code — the authoritative asset classification (e.g. "MUD MOTOR", "MWD/LWD"). Read-only. */
  fa_subclass_code: string | null
  name: string
  description: string | null
  serial_number: string | null
  model: string | null
  manufacturer: string | null
  operational_status: string | null
  maintenance_status: AssetMaintenanceStatus | null
  maintenance_sub_status: AssetMaintenanceSubStatus | null
  asset_kind: AssetKind | null
  asset_tag: string | null
  is_booked?: boolean // availability marker — reserved for a Job/Project
  parent_asset_id: number | null
  child_assets_count?: number
  current_location?: LocationRef | null
  erp_status?: string | null // not for Requester
  erp_last_synced_at?: string | null // not for Requester
  is_active?: boolean // Admin/Manager only
  erp_raw_data?: Record<string, unknown> // Admin only
  created_at: string
  updated_at: string
}

export interface AssetMeterReading {
  id: number
  asset_id: number
  usage_reading_type_id: number
  reading_value: string
  reading_at: string
  source: MeterReadingSource
  entered_by_user_id: number
  confirmed_by_user_id: number | null
  confirmed_at: string | null
  notes: string | null
}

export interface AssetLocationHistoryItem {
  id: number
  asset_id: number
  from_location_id: number | null
  to_location_id: number
  from_location?: LocationRef | null
  to_location?: LocationRef | null
  effective_at: string
  reason: string | null
  notes: string | null
  changed_by_user_id: number
}

/** Derived read-model — no table, assembled from WO/MR history. */
export interface MaintenanceHistoryItem {
  date: string | null
  type: MrType | null
  work_order_number: string
  maintenance_request_number: string | null
  description: string | null
  priority: Priority
  parts_used?: { part_name: string | null; quantity: number }[]
  closed_at: string | null
}

// ── Parts ─────────────────────────────────────────────────────────────────────

export interface Part {
  id: number
  erp_part_code: string
  name: string
  description: string | null
  unit_of_measure: string | null
  category: string | null
  available_quantity: number
  is_active: boolean
  erp_status?: string | null
  erp_last_synced_at?: string | null
  erp_raw_data?: Record<string, unknown>
  created_at: string
}

// ── Maintenance Requests ──────────────────────────────────────────────────────

export interface MaintenanceRequest {
  id: number
  number: string
  type: MrType
  status: MaintenanceRequestStatus
  priority: Priority
  description: string | null
  created_at: string
  asset: AssetRef
  created_by?: UserRef | null
  reviewed_by?: UserRef | null // Admin/Manager/Viewer
  // Failure classification (corrective only) — null until reviewed. Drives the MTBF
  // KPI. API field name is `is_failure`; always present on MaintenanceRequestResource.
  is_failure?: boolean | null
  rejection_reason?: string | null // hidden from Logistics
  cancellation_reason?: string | null // hidden from Logistics
  is_preventive?: boolean // Admin/Manager/Viewer
  triggered_by_date?: boolean // Admin/Manager/Viewer
  triggered_by_reading?: boolean // Admin/Manager/Viewer
  trigger_date?: string | null // "YYYY-MM-DD"
  trigger_reading_value?: string | null
  work_order?: { id: number; number: string; status: WorkOrderStatus } | null
  has_attachments?: number
}

// ── Work Orders ───────────────────────────────────────────────────────────────

export interface WorkOrderPart {
  id: number
  part: { id: number; name: string; erp_part_code: string; unit_of_measure: string | null }
  quantity: number
  notes: string | null
}

/**
 * Partial MR embedded in a WorkOrder payload (WorkOrderResource) — only the fields
 * the WO detail page needs to render the link + failure badge / close-override prompt
 * without a second fetch. `type` is intentionally omitted (derive it from
 * `is_preventive`); fetch the full request from GET /maintenance-requests/{id} when
 * other fields are needed.
 */
export interface WorkOrderMaintenanceRequestRef {
  id: number
  number: string
  is_preventive?: boolean | null
  is_failure?: boolean | null
}

export interface WorkOrder {
  id: number
  number: string
  status: WorkOrderStatus
  priority: Priority
  description: string | null
  asset: AssetRef
  created_at: string
  assigned_to?: UserRef | null // Admin/Manager/Tech/Viewer
  assigned_by?: UserRef | null // Admin/Manager only
  parts?: WorkOrderPart[] // Admin/Manager/Tech/Viewer
  started_at?: string | null
  completed_at?: string | null
  completion_notes?: string | null
  closed_at?: string | null
  cancelled_at?: string | null
  cancellation_reason?: string | null
  has_attachments?: number // Admin/Manager/Tech
  maintenance_request?: WorkOrderMaintenanceRequestRef | null
  form?: WoFormInstance | null // Admin/Manager/Tech — present when the WO has an attached form
}

// ── WO Forms ──────────────────────────────────────────────────────────────────

export interface WoFormTemplateField {
  id: number
  uuid: string
  label: string
  field_type: WoFormFieldType
  has_pre_post: boolean
  unit: string | null
  is_required: boolean
  sort_order: number
}

export interface WoFormTemplate {
  id: number
  name: string
  fa_subclass_code: string
  is_active: boolean
  fields?: WoFormTemplateField[]
  fields_count?: number
  created_at: string
}

/** Self-contained snapshot field — copies template metadata plus captured values. */
export interface WoFormFieldValue {
  id: number
  uuid: string
  label: string
  field_type: WoFormFieldType
  has_pre_post: boolean
  unit: string | null
  is_required: boolean
  sort_order: number
  pre_value: string | null
  post_value: string | null
  notes: string | null
}

export interface WoFormInstance {
  id: number
  form_template_id: number | null
  snapshotted_at: string
  template_is_stale?: boolean
  sync_dismissed_at?: string | null
  fields: WoFormFieldValue[]
}

/** 422 completion-gate payload entry — one per unfilled required field/slot. */
export interface MissingField {
  uuid: string
  label: string
  missing: ('pre' | 'post')[]
}

// ── PM Rules ──────────────────────────────────────────────────────────────────

export type PmStatus = 'ok' | 'soon' | 'due'

export interface PmSuppression {
  id: number
  decision_type?: string | null
  suppressed_until_date: string | null
  suppressed_until_reading: number | null
  source_mr_id: number | null
}

export interface PmRule {
  id: number
  name: string
  maintenance_level: string | null
  description: string | null
  trigger_type: PmTriggerType
  is_active: boolean
  interval_days: number | null
  interval_reading: number | null
  assignments_count?: number
  created_at: string
  updated_at?: string
  usage_reading_type?: { id: number; name: string; unit: string } | null
  assignments?: AssetPmAssignment[]
  created_by?: UserRef | null // Admin/Manager only
}

/** Nested rule template inside an assignment. */
export interface PmAssignmentRule {
  id: number
  name: string
  maintenance_level: string | null
  trigger_type: PmTriggerType
  interval_days: number | null
  interval_reading: number | null
  usage_reading_type?: { id: number; name: string; unit: string } | null
}

export interface AssetPmAssignment {
  id: number
  asset_id: number
  pm_rule_id: number
  is_active: boolean
  asset?: AssetRef
  last_triggered_date: string | null
  last_triggered_reading: number | null
  next_due_date: string | null
  next_due_reading: number | null
  progress_percentage: number | null
  pm_status: PmStatus
  rule: PmAssignmentRule
  assigned_by?: UserRef | null
  assigned_at?: string
  suppressions?: PmSuppression[]
}

// ── Attachments ───────────────────────────────────────────────────────────────

export interface Attachment {
  id: number
  file_name: string
  mime_type: string
  size_bytes: number
  description: string | null
  created_at: string
  download_url?: string // absent for Viewer
  uploaded_by?: UserRef | null // Admin/Manager only
  can_delete?: boolean // policy-driven (AttachmentResource); true when the current user may delete
}

// ── Admin resources ───────────────────────────────────────────────────────────

export interface Employee {
  id: number
  name: string
  emp_id: string | null
  email?: string | null
  department?: string | null
  job_title?: string | null
  user?: { id: number; name: string; role: Role } | null
}

export interface Location {
  id: number
  parent_id: number | null
  name: string
  type: string
  code: string | null
  description: string | null
  is_active: boolean
}

export interface MasterDataItem {
  id: number
  group_key: string
  value: string
  label: string
  sort_order: number | null
  is_active: boolean
}

export interface UsageReadingType {
  id: number
  name: string
  unit: string
  is_active: boolean
}

export interface FaSubclassTypeCode {
  id: number
  fa_subclass_code: string
  type_code: string
  description: string | null
  has_no_physical_size: boolean
}

export interface ErpSyncJob {
  id: number
  sync_type: 'assets' | 'parts'
  status: ErpSyncStatus
  total_records: number
  created_count: number
  updated_count: number
  failed_count: number
  error_message: string | null
  started_at: string
  completed_at: string | null
}

// Raw model columns as served by GET /api/admin/audit-logs (no AuditLogResource).
// See docs/atms/04-technical/BACKEND_API_HANDOFF.md.
export interface AuditLog {
  id: number
  user_id: number | null
  event: string
  subject_type: string | null
  subject_id: number | null
  before_state: Record<string, unknown> | null
  after_state: Record<string, unknown> | null
  metadata: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  request_id: string | null
  created_at: string
  // Full User object (hidden: password/remember_token/role_id); role NOT
  // eager-loaded. Nullable (system/unauthenticated events + nullOnDelete) →
  // render "System".
  actor: AuditActor | null
}

/** Trimmed User shape actually on the wire for an audit log `actor` (no role). */
export interface AuditActor {
  id: number
  name: string
  email: string
  is_active: boolean
  activated_at: string | null
  emp_id: string | null
  employee_id: number | null
  created_at: string
  updated_at: string
}

export interface CompanySettings {
  timezone: string
}

// ── Dashboard ─────────────────────────────────────────────────────────────────

// Every field is optional: the backend omits a key entirely when the current
// user's role isn't permitted to see it — absence (not zero/null) is the gate.
export interface DashboardSummary {
  pending_maintenance_requests?: number
  open_work_orders?: number
  overdue_pm_assignments?: number
  recently_closed_work_orders?: number
}

export interface DashboardData {
  summary: DashboardSummary
  pending_maintenance_requests?: MaintenanceRequest[]
  open_work_orders?: WorkOrder[]
  overdue_pm_assignments?: AssetPmAssignment[]
  recently_closed_work_orders?: WorkOrder[]
}

// ── Dashboard KPIs (GET /api/dashboard/kpis) ──────────────────────────────────
// Full payload to every role (not role-filtered), over a rolling 90-day window.
// Several scalars are `null` when there is no data in the window ("no basis to
// compute" — render an em dash, never 0). See DASHBOARD_KPI_HANDOFF.md §4.

export interface RelocatedAssetItem {
  id: number
  asset_id: number
  asset: { id: number; name: string; erp_asset_code: string; asset_tag: string }
  // `from_location` is null for an asset's first-ever placement (no prior
  // location). Verified against the live endpoint — the handoff type omits this.
  from_location: { id: number; name: string } | null
  to_location: { id: number; name: string } | null
  effective_at: string
  reason: string | null
  notes: string | null
  changed_by_user_id: number
  created_at: string
}

export interface DashboardKpiResponse {
  window: { days: number; from: string; to: string } // ISO 8601 bounds (UTC)
  kpis: {
    mtbf: { days: number | null }
    failure_rate: { failures: number; per_day: number }
    mttr: { hours: number | null }
    pm_compliance: { compliant: number; total: number; percentage: number | null }
    avg_mr_duration: { hours: number | null }
    avg_wo_duration: { hours: number | null }
    // Org-wide executive metrics (asset snapshot + workforce window). Always
    // present in the payload; inner scalars are null when there's no basis
    // (e.g. zero assets, zero created WOs, zero prior backlog).
    asset_health: {
      availability: { percentage: number | null }
      by_status: { active: number; under_maintenance: number; down: number; inactive: number }
      total: number
    }
    workforce: {
      wo_backlog: { total: number; trend_pct: number | null }
      completion_rate: { closed: number; created: number; percentage: number | null }
    }
  }
  recently_relocated_assets: RelocatedAssetItem[]
}

// ── Pagination ────────────────────────────────────────────────────────────────

export interface CursorMeta {
  path: string
  per_page: number
  next_cursor: string | null
  prev_cursor: string | null
}

export interface CursorLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export interface CursorPage<T> {
  data: T[]
  links: CursorLinks
  meta: CursorMeta
}

// ── Reports (GET /api/reports/*) ──────────────────────────────────────────────
// Read-only, parameterised aggregations. Spec: docs/atms/01-product/REPORTS.md;
// contract: .kilo/plans/1783838549346-reports-pass1-backend.md. Two response
// shapes: bounded `{ summary, items }` (R-1/R-2/R-7/R-10A) and cursor-paginated
// `{ summary, data, links, meta }` (R-8/R-14, summary merged in via additional()).

/** Aging bucket label. `91+` = ≥91 days (labels are honest — `31-90` includes day 90). */
export type AgingBucket = '0-7' | '8-30' | '31-90' | '91+'

// R-10A Operational Status Distribution
export interface OperationalStatusDistributionRow {
  status: string // OperationalStatus value: active | under_maintenance | down | inactive
  count: number
}
export interface OperationalStatusDistributionReport {
  summary: { total: number }
  items: OperationalStatusDistributionRow[]
}

// R-2 Asset Distribution by Location
export interface AssetsByLocationRow {
  location_id: number | null
  location_name: string | null // "Unassigned" when is_unassigned
  is_unassigned: boolean
  asset_count: number
  by_operational_status: { active: number; under_maintenance: number; down: number; inactive: number }
  by_asset_kind: { standalone: number; package: number; component: number }
  booked_count: number
}
export interface AssetsByLocationReport {
  summary: { total_assets: number; total_locations: number; total_booked: number }
  items: AssetsByLocationRow[]
}

// R-7 PM Compliance (group_by: rule | asset | location)
export type PmComplianceGroupBy = 'rule' | 'asset' | 'location'
export interface PmComplianceRow {
  group_key: string | number | null
  group_label: string | null
  compliant: number
  total: number
  percentage: number | null // null when total = 0
}
export interface PmComplianceReport {
  summary: { compliant: number; total: number; percentage: number | null }
  items: PmComplianceRow[]
}

// R-1 Upcoming PM Schedule (date-triggered only)
export type PmChainStatus =
  | 'not_yet_generated'
  | 'generated_mr_pending'
  | 'wo_open'
  | 'wo_completed'
export interface UpcomingPmItem {
  assignment_id: number
  asset: { id: number; name: string; asset_tag: string | null; erp_asset_code: string }
  // Always an object; inner id/name are null when the asset has no current location.
  location: { id: number | null; name: string | null }
  pm_rule: { id: number; name: string }
  trigger_type: PmTriggerType
  next_due_date: string // ISO date
  days_until_due: number
  chain_status: PmChainStatus
}
export interface UpcomingPmReport {
  summary: {
    total: number
    by_trigger_type: Record<string, number>
    by_due_week: Record<string, number> // ISO week key "2026-W28" → count
  }
  items: UpcomingPmItem[]
}

// R-8 Overdue PM (cursor-paginated; item extends MaintenanceRequest)
export interface OverduePmItem extends MaintenanceRequest {
  days_overdue: number
  bucket: AgingBucket
}
export interface OverduePmReportPage extends CursorPage<OverduePmItem> {
  // Facet context (D8): all 4 buckets over the scoped set, independent of the
  // `bucket` row filter. `total` is the scoped grand total.
  summary: { total: number; by_bucket: Record<AgingBucket, number> }
}

// R-14 WO Backlog / Aging (cursor-paginated; item extends WorkOrder)
export interface WoBacklogItem extends WorkOrder {
  age_days: number
  bucket: AgingBucket
}
export interface WoBacklogReportPage extends CursorPage<WoBacklogItem> {
  summary: {
    total: number
    by_bucket: Record<AgingBucket, number>
    by_priority: Record<string, number>
  }
}

// ── Reports Pass 2 (stable-contract subset) ──────────────────────────────────
// R-3 MTBF, R-4 MTTR, R-6 Bad-Actor, R-13 Booking, R-17 Parts, R-20 Meter, R-21
// Suppression. (R-9/R-15/R-16/R-18/R-19 pending backend rework — not typed yet.)
// The "category" group-by resolves to fa_subclass_code server-side.

export type MtbfGroupBy = 'asset' | 'category' | 'location'
export type MttrGroupBy = 'asset' | 'category' | 'technician'

// R-3 MTBF / Failure Rate by dimension
export interface MtbfRow {
  group_key: string | number | null
  group_label: string | null
  failure_count: number
  mtbf_days: number | null
  failure_rate_per_day: number
}
export interface MtbfReport {
  summary: { mtbf_days: number | null; failure_count: number; failure_rate_per_day: number }
  items: MtbfRow[]
}

// R-4 MTTR by dimension
export interface MttrRow {
  group_key: string | number | null
  group_label: string | null
  repair_count: number
  mttr_hours: number | null
}
export interface MttrReport {
  summary: { mttr_hours: number | null; repair_count: number }
  items: MttrRow[]
}

// R-6 Bad-Actor / Breakdown Analysis
export interface BadActorRow {
  group_key: string | number | null
  group_label: string | null
  failure_count: number
}
export interface BadActorReport {
  summary: { total_failures: number }
  items: BadActorRow[]
}

// R-13 Asset Booking / Availability
export interface BookingRow {
  location_id: number | null
  location_name: string | null
  total_count: number
  booked_count: number
  available_count: number
}
export interface BookingReport {
  summary: { total_assets: number; booked_count: number; available_count: number }
  items: BookingRow[]
}

// R-17 Parts Consumption (cursor)
export interface PartsConsumptionItem {
  part_id: number
  part_code: string
  part_name: string
  unit_of_measure: string | null
  fa_subclass_code: string | null
  total_quantity: number
  line_item_count: number
  work_order_count: number
}
export interface PartsConsumptionReportPage extends CursorPage<PartsConsumptionItem> {
  // total_quantity/unit_of_measure are null when the query mixes units (unfiltered).
  summary: {
    total_line_items: number
    distinct_parts: number
    distinct_work_orders: number
    total_quantity: number | null
    unit_of_measure: string | null
  }
}

// R-20 Meter Reading Progression (cursor)
export interface MeterProgressionItem {
  id: number
  asset: { id: number | null; name: string | null; erp_asset_code: string | null }
  reading_type: { id: number | null; name: string | null; unit: string | null }
  reading_value: number
  previous_reading_value: number | null
  delta: number | null
  reading_at: string | null
  confirmed_at: string | null
  source: string | null
}
export interface MeterProgressionReportPage extends CursorPage<MeterProgressionItem> {
  summary: { total_readings: number; confirmed_readings: number }
}

// R-21 PM Suppression Register (cursor)
export interface PmSuppressionItem {
  id: number
  decision_type: string | null
  trigger_type: PmTriggerType | null
  triggered_by_date: boolean | null
  triggered_by_reading: boolean | null
  trigger_date: string | null
  trigger_reading_value: number | null
  trigger_reading_type: { id: number; name: string; unit: string } | null
  suppressed_until_date: string | null
  suppressed_until_reading: number | null
  pm_rule: { id: number | null; name: string | null }
  asset: { id: number | null; name: string | null; erp_asset_code: string | null }
  maintenance_request: { id: number | null; number: string | null }
  decided_by: { id: number | null; name: string | null }
  decided_at: string | null
  reason: string | null
}
export interface PmSuppressionReportPage extends CursorPage<PmSuppressionItem> {
  summary: { total_suppressions: number }
}

// ── Reports Pass 2 (reworked subset: R-9, R-15, R-16, R-18, R-19) ─────────────
// R-15/R-16 return a cursor `meta` but NO `links` object.

// R-9 PM Coverage — cursor; items are role-gated AssetResource objects.
export interface PmCoverageReportPage extends CursorPage<Asset> {
  summary: {
    total_assets: number
    covered_assets: number
    uncovered_assets: number
    coverage_pct: number | null
  }
}

// R-15 Technician Workload — cursor (meta only, no links).
export interface TechnicianWorkloadRow {
  technician_id: number
  technician_name: string
  total_count: number
  open_count: number
  in_progress_count: number
  completed_count: number
  cancelled_count: number
  backlog_count: number
  avg_duration_hours: number | null
  avg_backlog_age_days: number | null
}
export interface TechnicianWorkloadReportPage {
  summary: {
    total_work_orders: number
    total_assigned: number
    total_open: number
    total_in_progress: number
    total_completed: number
    total_cancelled: number
    total_backlog: number
    avg_duration_hours: number | null
    avg_backlog_age_days: number | null
  }
  data: TechnicianWorkloadRow[]
  meta: { next_cursor: string | null; prev_cursor: string | null }
}

// R-16 MR/WO Throughput — cursor (meta only, no links). One row per day.
export interface ThroughputRow {
  date: string
  mr_created: number
  mr_pending_review: number
  mr_converted: number
  mr_rejected: number
  mr_cancelled: number
  wo_created: number
  wo_open: number
  wo_in_progress: number
  wo_completed: number
  wo_closed: number
  wo_cancelled: number
}
export interface ThroughputReportPage {
  summary: {
    mr_created: number
    mr_pending_review: number
    mr_converted: number
    mr_rejected: number
    mr_cancelled: number
    wo_created: number
    wo_open: number
    wo_in_progress: number
    wo_completed: number
    wo_closed: number
    wo_cancelled: number
    avg_conversion_hours: number | null
  }
  data: ThroughputRow[]
  meta: { next_cursor: string | null; prev_cursor: string | null }
}

// R-18 Asset Movement — cursor. Nested via AssetLocationHistoryResource.
export interface AssetMovementItem {
  id: number
  asset_id: number
  asset: { id: number; name: string; erp_asset_code: string; asset_tag: string | null }
  from_location: { id: number; name: string } | null // null on first placement
  to_location: { id: number; name: string } | null
  effective_at: string
  reason: string | null
  notes: string | null
  changed_by_user_id: number // no name exposed by the resource
  created_at: string
}
export interface AssetMovementReportPage extends CursorPage<AssetMovementItem> {
  summary: { total_movements: number; unique_assets_moved: number }
}

// R-19 Work Order Form Results — cursor; paginated field values + numeric summary.
export interface FormNumericComparison {
  field_uuid: string
  label: string
  unit: string | null
  comparison_count: number
  avg_pre_value: number | null
  avg_post_value: number | null
  avg_change: number | null
}
export interface FormResultRow {
  id: number
  field_uuid: string
  label: string
  field_type: WoFormFieldType
  has_pre_post: boolean
  unit: string | null
  pre_value: string | null
  post_value: string | null
  notes: string | null
  work_order: { id: number; number: string }
  asset: { id: number; name: string; erp_asset_code: string; fa_subclass_code: string | null }
}
export interface FormResultsReportPage extends CursorPage<FormResultRow> {
  summary: {
    total_fields: number
    boolean_true_count: number
    boolean_false_count: number
    numeric_pre_post_count: number
    numeric_comparisons: FormNumericComparison[]
  }
}

// ── Mutation response wrappers ────────────────────────────────────────────────

export interface MessageResponse {
  message: string
}
export interface DataResponse<T> {
  message?: string
  data: T
}
